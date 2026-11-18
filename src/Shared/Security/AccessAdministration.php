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
            // Sperre auf dem Zielkonto: gleichzeitige Aenderungen desselben Kontos laufen nacheinander.
            $lock = DB::getInstance()->prepare('SELECT `id` FROM `accounts` WHERE `public_user_id` = :id FOR UPDATE');
            $lock->execute(['id' => $targetId]);
            if ($lock->fetchColumn() === false) {
                throw new CodedException('account_not_found', 404, 'Das Konto gibt es nicht.');
            }
            $roles = array_column((new RoleOverview())->list(), null, 'id');
            $role = $roles[$roleId] ?? throw new CodedException('role_not_found', 404, 'Die Rolle gibt es nicht.');

            $refusal = (new RoleAssignmentRules($actor->accountId, $roles))->refusal($targetId, $role, $assign);
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
     * Gewaehrt einem Konto ein Recht zusaetzlich zu seinen Rollen (Einzelrecht, Ticket #36). Ersetzt ein
     * entzogenes Einzelrecht. Hat es das Einzelrecht schon, geschieht nichts (kein Audit-Eintrag).
     * Schreibt `permission.granted` mit Recht und Modus `added`.
     */
    public function grantPermission(Actor $actor, string $targetId, string $permission): void
    {
        $this->changeOverride($actor, $targetId, $permission, PermissionOverrideRules::GRANT);
    }

    /** Entzieht einem Konto ein Recht trotz seiner Rollen; schreibt `permission.withdrawn` mit Modus `removed`. */
    public function withdrawPermission(Actor $actor, string $targetId, string $permission): void
    {
        $this->changeOverride($actor, $targetId, $permission, PermissionOverrideRules::WITHDRAW);
    }

    /**
     * Setzt ein Einzelrecht zurueck: es gilt wieder, was die Rollen ergeben. Schreibt `permission.override_cleared`
     * mit dem zurueckgesetzten Modus (`added` oder `removed`).
     */
    public function clearPermissionOverride(Actor $actor, string $targetId, string $permission): void
    {
        $this->changeOverride($actor, $targetId, $permission, PermissionOverrideRules::CLEAR);
    }

    private function changeOverride(Actor $actor, string $targetId, string $permission, string $action): void
    {
        $this->atomically(function () use ($actor, $targetId, $permission, $action): void {
            $this->lockAccount($targetId);
            $refusal = (new PermissionOverrideRules($actor->accountId, array_column((new RoleOverview())->list(), null, 'id')))->refusal($targetId, $permission, $action);
            if ($refusal !== null) {
                throw $refusal;
            }
            $service = PermissionCatalog::service();
            $access = $service->getUserAccess($targetId);
            $current = $access['overrides'][$permission] ?? null;
            $overrides = $access['overrides'];
            if ($action === PermissionOverrideRules::CLEAR) {
                $mode = $current;
                unset($overrides[$permission]);
            } else {
                $mode = $action === PermissionOverrideRules::GRANT ? 'added' : 'removed';
                $overrides[$permission] = $mode;
            }
            if ($mode === null || ($action !== PermissionOverrideRules::CLEAR && $current === $mode)) {
                return;
            }
            $service->setUserAccess($targetId, $access['role_ids'], $overrides);
            $this->audit->record(
                $actor,
                match ($action) {
                    PermissionOverrideRules::GRANT => AuditLog::PERMISSION_GRANTED,
                    PermissionOverrideRules::WITHDRAW => AuditLog::PERMISSION_WITHDRAWN,
                    PermissionOverrideRules::CLEAR => AuditLog::PERMISSION_OVERRIDE_CLEARED,
                },
                'account',
                $targetId,
                ['permission' => $permission, 'mode' => $mode],
            );
        });
    }

    /** Sperrt die Zeile des Zielkontos (gleichzeitige Aenderungen desselben Kontos laufen nacheinander). */
    private function lockAccount(string $targetId): void
    {
        $lock = DB::getInstance()->prepare('SELECT `id` FROM `accounts` WHERE `public_user_id` = :id FOR UPDATE');
        $lock->execute(['id' => $targetId]);
        if ($lock->fetchColumn() === false) {
            throw new CodedException('account_not_found', 404, 'Das Konto gibt es nicht.');
        }
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
