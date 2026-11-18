<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Der einzige Weg, Rollen zu vergeben oder zu entziehen (ADR 0002 B4); jede Aktion
 * erzeugt einen Audit-Eintrag. Minimal: #11 baut Rechtepruefung und Invarianten aus.
 * Laeuft in der Transaktion des Aufrufers mit (PermissionRepository::transaction verschachtelt).
 */
final class AccessAdministration
{
    public const ROLE_SCHULADMIN = 'schuladmin';

    public function __construct(private readonly AuditLog $audit = new AuditLog())
    {
    }

    /** Vergibt die Rolle Schuladmin. Hat das Konto sie schon, geschieht nichts (kein doppelter Audit-Eintrag). */
    public function grantInitialAdmin(string $publicUserId, Actor $actor): void
    {
        $service = PermissionCatalog::service();
        $roleId = $service->ensureSystemRole(self::ROLE_SCHULADMIN, 'Schuladmin');
        if (in_array($roleId, $service->getUserAccess($publicUserId)['role_ids'], true)) {
            return;
        }
        $service->assignRole($publicUserId, $roleId);
        MfaRequirement::recomputeFor([$publicUserId]);
        $this->audit->record($actor, AuditLog::ROLE_ASSIGNED, 'account', $publicUserId, ['role' => self::ROLE_SCHULADMIN, 'name' => 'Schuladmin']);
    }

    /** Entzieht einem Konto alle Rollen (z. B. wenn eine nie angenommene Einladung ersetzt wird). */
    public function revokeAllRoles(string $publicUserId, Actor $actor): void
    {
        $service = PermissionCatalog::service();
        $access = $service->getUserAccess($publicUserId);
        if ($access['role_ids'] === [] && $access['overrides'] === []) {
            return;
        }
        $service->setUserAccess($publicUserId, [], []);
        MfaRequirement::recomputeFor([$publicUserId]);
        $this->audit->record($actor, AuditLog::ROLE_REVOKED, 'account', $publicUserId);
    }

    /**
     * Vergibt einem Konto eine Rolle, additiv (Ticket #34). Hat es sie schon, geschieht nichts (kein Audit-Eintrag).
     * Prueft P2, P6 und "Schuladmin ohne Einzelrechte" ({@see RoleAssignmentRules}), berechnet `mfa_required`
     * neu und schreibt `role.assigned` mit Rollenschluessel und -name, alles in einer Transaktion.
     *
     * @throws CodedException `account_not_found`, `role_not_found` (404); `self_escalation`, `permission_not_held` (403);
     *                        `student_role_exclusive`, `admin_overrides_forbidden` (409)
     */
    public function assignRole(Actor $actor, string $targetId, string $roleId): void
    {
        $this->changeRole($actor, $targetId, $roleId, true);
    }

    /**
     * Entzieht einem Konto genau eine Rolle; andere Rollen und Einzelrechte bleiben (Ticket #34). Hat es die Rolle
     * nicht, geschieht nichts. Gleiche Pruefungen wie {@see self::assignRole()}; schreibt `role.revoked`.
     *
     * @throws CodedException wie assignRole (ohne die 409 der Vergabe)
     */
    public function revokeRole(Actor $actor, string $targetId, string $roleId): void
    {
        $this->changeRole($actor, $targetId, $roleId, false);
    }

    private function changeRole(Actor $actor, string $targetId, string $roleId, bool $assign): void
    {
        $this->atomically(function () use ($actor, $targetId, $roleId, $assign): void {
            // Zuerst die Rolle sperren: fuer Schuladmin ist das der Mutex von P3 ({@see LastAdminGuard}). Die Reihenfolge
            // Rolle, dann Zielkonto gilt fuer Vergeben und Entziehen, damit sich zwei Admins nicht gegenseitig verklemmen.
            $roleLock = DB::getInstance()->prepare('SELECT `id` FROM `permission_roles` WHERE `id` = :id FOR UPDATE');
            $roleLock->execute(['id' => $roleId]);
            // Sperre auf dem Zielkonto: gleichzeitige Aenderungen desselben Kontos laufen nacheinander.
            $lock = DB::getInstance()->prepare('SELECT `id` FROM `accounts` WHERE `public_user_id` = :id FOR UPDATE');
            $lock->execute(['id' => $targetId]);
            if ($lock->fetchColumn() === false) {
                throw new CodedException('account_not_found', 404, 'Das Konto gibt es nicht.');
            }
            $roles = array_column((new RoleOverview())->list(), null, 'id');
            $role = $roles[$roleId] ?? throw new CodedException('role_not_found', 404, 'Die Rolle gibt es nicht.');

            $refusal = (new RoleAssignmentRules($actor->accountId, $roles, lockAdmins: true))->refusal($targetId, $role, $assign);
            if ($refusal !== null) {
                throw $refusal;
            }

            $service = PermissionCatalog::service();
            $access = $service->getUserAccess($targetId);
            $held = in_array($roleId, $access['role_ids'], true);
            if ($held === $assign) {
                return;
            }
            if ($assign) {
                $service->assignRole($targetId, $roleId);
            } else {
                $service->setUserAccess($targetId, array_values(array_diff($access['role_ids'], [$roleId])), $access['overrides']);
            }
            MfaRequirement::recomputeFor([$targetId]);
            $this->audit->record(
                $actor,
                $assign ? AuditLog::ROLE_ASSIGNED : AuditLog::ROLE_REVOKED,
                'account',
                $targetId,
                ['role' => $role['key'], 'name' => $role['name']],
            );
        });
    }

    /**
     * P3 als eigene Operation (Ticket #35): stellt sicher, dass nach einer Aktion, die den Konten $leavingIds die Rolle
     * Schuladmin oder den Status `active` nimmt, mindestens ein aktiver Schuladmin uebrig bleibt. Fuer die spaeter
     * folgende Deaktivierung von Konten und jede weitere Aktion, die den Kreis der Admins verkleinern kann.
     *
     * Aufruf in der Transaktion der Aktion (sonst oeffnet sie eine eigene), als ERSTES, bevor das Zielkonto gesperrt wird:
     * Sie sperrt zuerst die Rolle Schuladmin (Mutex), dann die aktiven Admin-Konten, und liest danach den neuesten Stand.
     * Konten, die kein aktiver Schuladmin sind, aendern den Bestand nicht (nie eine Ablehnung).
     *
     * @param list<string> $leavingIds public_user_ids der Konten, die als aktive Schuladmins ausscheiden wuerden
     * @throws CodedException `last_admin` (409)
     */
    public function ensureAdminRemains(array $leavingIds): void
    {
        $this->atomically(function () use ($leavingIds): void {
            $refusal = (new LastAdminGuard())->refusal($leavingIds, true);
            if ($refusal !== null) {
                throw $refusal;
            }
        });
    }

    /** Fuehrt $work in einer Transaktion aus (oder in der des Aufrufers). */
    private function atomically(callable $work): void
    {
        $pdo = DB::getInstance();
        if ($pdo->inTransaction()) {
            $work();
            return;
        }
        $pdo->beginTransaction();
        try {
            $work();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
