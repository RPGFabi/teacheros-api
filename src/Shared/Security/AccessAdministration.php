<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\AuthService\AuthServiceClient;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Der einzige Weg, Rollen zu vergeben oder zu entziehen (ADR 0002 B4); jede Aktion
 * erzeugt einen Audit-Eintrag. Minimal: #11 baut Rechtepruefung und Invarianten aus.
 * Laeuft in der Transaktion des Aufrufers mit (PermissionRepository::transaction verschachtelt).
 */
final class AccessAdministration
{
    public const ROLE_SCHULADMIN = 'schuladmin';

    public function __construct(
        private readonly AuditLog $audit = new AuditLog(),
        private readonly RoleSettingsRepository $settings = new RoleSettingsRepository(),
    ) {
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

    /**
     * Entzieht einem Konto alle Rollen und setzt alle Einzelrechte zurueck (z. B. wenn eine nie angenommene Einladung
     * ersetzt wird). Prueft P3 ({@see self::ensureAdminRemains()}, als Erstes: Rolle vor Konto) und schreibt je Rolle
     * `role.revoked` (Rollenschluessel, -name) und je Einzelrecht `permission.override_cleared` (Recht, Modus).
     *
     * @throws CodedException `last_admin` (409)
     */
    public function revokeAllRoles(string $publicUserId, Actor $actor): void
    {
        $this->atomically(function () use ($publicUserId, $actor): void {
            $this->ensureAdminRemains([$publicUserId]);
            $service = PermissionCatalog::service();
            $access = $service->getUserAccess($publicUserId);
            if ($access['role_ids'] === [] && $access['overrides'] === []) {
                return;
            }
            $roles = (new RoleOverview())->byId();
            $service->setUserAccess($publicUserId, [], []);
            MfaRequirement::recomputeFor([$publicUserId]);
            foreach ($access['role_ids'] as $roleId) {
                $this->audit->record($actor, AuditLog::ROLE_REVOKED, 'account', $publicUserId, ['role' => $roles[$roleId]['key'], 'name' => $roles[$roleId]['name']]);
            }
            foreach ($access['overrides'] as $permission => $mode) {
                $this->audit->record($actor, AuditLog::PERMISSION_OVERRIDE_CLEARED, 'account', $publicUserId, ['permission' => $permission, 'mode' => $mode]);
            }
        });
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
            $this->lockRole($roleId);
            // Sperre auf dem Zielkonto: gleichzeitige Aenderungen desselben Kontos laufen nacheinander.
            (new AccountRepository())->lock($targetId);
            $roles = (new RoleOverview())->byId();
            $role = $roles[$roleId];

            $this->throwIfRefused((new RoleAssignmentRules($actor->accountId, $roles, lockAdmins: true))->refusal($targetId, $role, $assign));

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
            $this->throwIfRefused((new LastAdminGuard())->refusal($leavingIds, true));
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
            (new AccountRepository())->lock($targetId);
            $this->throwIfRefused((new PermissionOverrideRules($actor->accountId, (new RoleOverview())->byId()))->refusal($targetId, $permission, $action));
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

    /**
     * Deaktiviert ein aktives Konto auf Veranlassung der Verwaltung (Ticket #41, ADR 0002 B2 und Nachtrag #12): setzt
     * Status, Grund `admin_locked`, `inactive_since` und `sessions_valid_after`, schreibt `account.deactivated`
     * (`reason`). Rollen und Einzelrechte bleiben. Regeln: {@see AccountDeactivationRules}.
     *
     * Alles in einer Transaktion. Sperrreihenfolge wie bei P3 vorgegeben: zuerst der Mutex der Schuladmins
     * ({@see LastAdminGuard::lockMutex()}), dann das Zielkonto, dann die Admin-Zeilen (in der Regelpruefung).
     * Erst nach dem lokalen Commit folgt der Abgleich mit dem Anmeldedienst ({@see OpenCalls}, `disable-login`,
     * Tickets #42, #47); scheitert er, bleibt die Deaktivierung bestehen (B2: erst lokal, dann Dienst) und das Konto traegt
     * "Abgleich offen". Der Aufrufer darf deshalb keine eigene Transaktion offen haben.
     *
     * @throws CodedException `account_not_found` (404); `forbidden`, `permission_not_held` (403);
     *                        `self_deactivation`, `account_not_active`, `last_admin` (409)
     */
    public function deactivateAccount(Actor $actor, string $targetId, AuthServiceClient $authService = new AuthServiceClient()): void
    {
        $this->atomically(function () use ($actor, $targetId): void {
            LastAdminGuard::lockMutex();
            $target = (new AccountRepository())->lock($targetId);

            $this->throwIfRefused((new AccountDeactivationRules($actor->accountId, lockAdmins: true))->refusal($target));

            (new AccountRepository())->deactivate($targetId, InactiveReason::AdminLocked);
            (new AccountRepository())->addOpenCall($targetId, DisableLoginCall::OPERATION);
            $this->audit->record($actor, AuditLog::ACCOUNT_DEACTIVATED, 'account', $targetId, ['reason' => InactiveReason::AdminLocked->value]);
        });
        (new OpenCalls($authService))->attempt($targetId);
    }

    /**
     * Reaktiviert ein deaktiviertes Konto (Ticket #43, ADR 0002 B2 und Nachtrag #12): umgekehrte Reihenfolge zur Deaktivierung.
     * Zuerst gibt `enable-login` den Login im Anmeldedienst frei (synchron), erst bei Erfolg wird das Konto lokal `active`
     * (Grund, `inactive_since` und "Abgleich offen" entfallen, `sessions_valid_after` bleibt) und `account.reactivated` geschrieben.
     * Lehnt der Dienst ab oder ist er nicht erreichbar, aendert sich lokal nichts. Regeln: {@see AccountReactivationRules}.
     *
     * Eine Transaktion mit gesperrter Kontozeile, wie beim Abgleich ({@see OpenCalls}):
     * ein gleichzeitiger "Abgleich wiederholen" wartet und findet dann ein aktives Konto.
     *
     * @throws CodedException `account_not_found` (404); `forbidden`, `permission_not_held` (403); `self_reactivation`,
     *                        `account_not_inactive` (409); der Anmeldedienst lehnt fachlich ab: z. B. `self_deleted`,
     *                        `email_unconfirmed` (409); nicht erreichbar: `auth_unavailable` (502)
     */
    public function reactivateAccount(Actor $actor, string $targetId, AuthServiceClient $authService = new AuthServiceClient()): void
    {
        $this->atomically(function () use ($actor, $targetId, $authService): void {
            $target = (new AccountRepository())->lock($targetId);

            $this->throwIfRefused((new AccountReactivationRules($actor->accountId))->refusal($target));

            $authService->enableLogin($targetId);

            (new AccountRepository())->reactivate($targetId);
            $this->audit->record($actor, AuditLog::ACCOUNT_REACTIVATED, 'account', $targetId, []);
        });
    }

    /**
     * Beendet alle Sitzungen eines aktiven Kontos auf Veranlassung der Verwaltung (Ticket #48, ADR 0002 B2 und Nachtrag #16):
     * zuerst lokal die Sitzungsgrenze, der offene Aufruf und `account.sessions_revoked` (`initiated_by: admin`), nach dem
     * Commit G3 (`revoke-sessions`, {@see SessionRevocation}). Scheitert G3, bleibt der Widerruf bestehen, der Aufruf offen und
     * jedes Token des Kontos abgelehnt ({@see TokenBlockingCall}); die Lesesicht nennt ihn in `account.open_calls`.
     * Regeln: {@see AccountRevocationRules}. Der Aufrufer darf keine eigene Transaktion offen haben.
     *
     * @throws CodedException `account_not_found` (404); `forbidden`, `permission_not_held` (403);
     *                        `self_revocation`, `account_not_active` (409)
     */
    public function revokeSessions(Actor $actor, string $targetId, AuthServiceClient $authService = new AuthServiceClient()): void
    {
        (new SessionRevocation($this->audit, new AccountRepository(), $authService))->revoke(
            $actor,
            $targetId,
            RevocationInitiator::Administration,
            precondition: static fn (Account $target) => (new AccountRevocationRules($actor->accountId))->refusal($target),
        );
    }

    /**
     * Setzt das TOTP eines aktiven oder deaktivierten Kontos zurueck (Ticket #49, ADR 0002 B2 und Nachtrag #16): beendet wie der
     * Widerruf alle Sitzungen (zuerst lokal die Sitzungsgrenze, der offene Aufruf und `account.mfa_reset` ohne Kontext, nach dem
     * Commit G1, `mfa-reset`; {@see SessionTermination}). Scheitert G1, bleiben Zuruecksetzung und Sitzungsgrenze bestehen, der Aufruf
     * offen und jedes Token des Kontos abgelehnt ({@see TokenBlockingCall}). Ein Konto mit MFA-Pflicht richtet TOTP danach neu ein.
     * Regeln: {@see AccountMfaResetRules}. Der Aufrufer darf keine eigene Transaktion offen haben.
     *
     * @throws CodedException `account_not_found` (404); `forbidden`, `permission_not_held` (403);
     *                        `self_mfa_reset`, `account_not_active` (409)
     */
    public function resetMfa(Actor $actor, string $targetId, AuthServiceClient $authService = new AuthServiceClient()): void
    {
        (new SessionTermination($this->audit, new AccountRepository(), $authService))->terminate(
            $actor,
            $targetId,
            ResetMfaCall::OPERATION,
            AuditLog::ACCOUNT_MFA_RESET,
            [],
            precondition: static fn (Account $target) => (new AccountMfaResetRules($actor->accountId))->refusal($target),
        );
    }

    /**
     * Fuehrt $work in einer Transaktion aus (oder in der des Aufrufers). Wer die Transaktion selbst oeffnet, spiegelt nach
     * dem Commit geaenderte MFA-Pflichten an den Anmeldedienst (Ticket #15); bei einer fremden Transaktion tut das deren Besitzer.
     */
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
        (new MfaRequirementSync())->pushPending();
    }

    // --- Rollen anlegen, bearbeiten, loeschen (Ticket #33) ---

    /**
     * Legt eine eigene Rolle an. Den Schluessel vergibt die API zufaellig; er ist unveraenderlich.
     * Ohne Wildcard darf der Akteur nur Rechte vergeben, die er selbst effektiv besitzt (P2).
     *
     * @param list<string> $permissions bereits gegen den Katalog geprueft
     * @return string Rollen-ID
     * @throws CodedException `permission_not_held` (403)
     */
    public function createRole(Actor $actor, string $name, ?string $description, array $permissions, bool $requiresMfa): string
    {
        $id = '';
        $this->atomically(function () use ($actor, $name, $description, $permissions, $requiresMfa, &$id): void {
            $rules = new RoleManagementRules($actor->accountId);
            foreach ($permissions as $permission) {
                $this->throwIfRefused($rules->addPermissionRefusal(null, $permission));
            }
            $role = PermissionCatalog::service()->createRole('r_' . bin2hex(random_bytes(8)), $name, $description, $permissions);
            $this->settings->registerIfMissing($role['id'], RoleProtection::Custom, $requiresMfa);
            $this->audit->record($actor, AuditLog::ROLE_CREATED, 'role', $role['id'], ['role' => $role['role_key'], 'name' => $role['name']]);
            $id = $role['id'];
        });
        return $id;
    }

    /**
     * Aendert Name, Beschreibung, Rechte und (bei eigenen Rollen) das MFA-Merkmal. Aendert sich das
     * Merkmal, wird `mfa_required` aller Konten mit der Rolle in derselben Transaktion neu berechnet.
     * Ohne tatsaechliche Aenderung entsteht kein Audit-Eintrag.
     *
     * @throws CodedException `role_not_found` (404), `role_protected` (409), `permission_not_held`, `self_escalation` (403)
     */
    public function updateRole(Actor $actor, string $roleId, RoleChanges $changes): void
    {
        $this->atomically(function () use ($actor, $roleId, $changes): void {
            $this->lockRole($roleId);
            $role = (new RoleOverview())->byId()[$roleId];
            $rules = new RoleManagementRules($actor->accountId);

            $this->throwIfRefused($rules->refusal($role, RoleManagementRules::EDIT));
            $mfaChanges = $changes->requiresMfa !== null && $changes->requiresMfa !== $role['requires_mfa'];
            if ($mfaChanges) {
                $this->throwIfRefused($rules->refusal($role, RoleManagementRules::SET_MFA));
            }

            $added = $changes->permissions === null ? [] : array_values(array_diff($changes->permissions, $role['permissions']));
            $removed = $changes->permissions === null ? [] : array_values(array_diff($role['permissions'], $changes->permissions));
            foreach ($added as $permission) {
                $this->throwIfRefused($rules->addPermissionRefusal($role, $permission));
            }

            $name = $changes->name ?? $role['name'];
            $description = $changes->changesDescription ? $changes->description : $role['description'];
            $descriptionChanged = $changes->changesDescription && trim((string) $description) !== (string) $role['description'];
            $renamed = trim($name) !== $role['name'];
            if (!$renamed && !$descriptionChanged && $added === [] && $removed === [] && !$mfaChanges) {
                return;
            }

            $updated = PermissionCatalog::service()->updateRole($roleId, $name, $description, $changes->permissions);
            if ($mfaChanges) {
                $this->settings->setRequiresMfa($roleId, (bool) $changes->requiresMfa);
                MfaRequirement::recomputeFor($this->accountsWithRole($roleId));
            }

            $context = ['role' => $updated['role_key'], 'name' => $updated['name']];
            if ($renamed) {
                $context['old_name'] = $role['name'];
            }
            if ($descriptionChanged) {
                $context['description_changed'] = true;
            }
            if ($added !== []) {
                $context['added'] = implode(',', $added);
            }
            if ($removed !== []) {
                $context['removed'] = implode(',', $removed);
            }
            if ($mfaChanges) {
                $context['requires_mfa'] = (bool) $changes->requiresMfa;
            }
            $this->audit->record($actor, AuditLog::ROLE_UPDATED, 'role', $roleId, $context);
        });
    }

    /**
     * Loescht eine eigene Rolle, die keinem Konto mehr zugewiesen ist (das Cascade des Pakets greift damit nie).
     *
     * @throws CodedException `role_not_found` (404), `role_protected`, `role_in_use` (409)
     */
    public function deleteRole(Actor $actor, string $roleId): void
    {
        $this->atomically(function () use ($actor, $roleId): void {
            // Die Sperre auf der Rollenzeile haelt auch gleichzeitige Zuweisungen (Fremdschluessel) bis zum Ende auf.
            $this->lockRole($roleId);
            $role = (new RoleOverview())->byId()[$roleId];
            $this->throwIfRefused((new RoleManagementRules($actor->accountId))->refusal($role, RoleManagementRules::DELETE));
            PermissionCatalog::service()->deleteRole($roleId);
            $this->audit->record($actor, AuditLog::ROLE_DELETED, 'role', $roleId, ['role' => $role['key'], 'name' => $role['name']]);
        });
    }

    /** @throws CodedException die Ablehnung, wenn es eine gibt */
    private function throwIfRefused(?CodedException $refusal): void
    {
        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /** @throws CodedException `role_not_found` (404) */
    private function lockRole(string $roleId): void
    {
        $stmt = DB::getInstance()->prepare('SELECT `id` FROM `permission_roles` WHERE `id` = :id FOR UPDATE');
        $stmt->execute(['id' => $roleId]);
        if ($stmt->fetchColumn() === false) {
            throw new CodedException('role_not_found', 404, 'Die Rolle gibt es nicht.');
        }
    }

    /** @return list<string> */
    private function accountsWithRole(string $roleId): array
    {
        $stmt = DB::getInstance()->prepare('SELECT `user_uuid` FROM `permission_user_roles` WHERE `role_id` = :id');
        $stmt->execute(['id' => $roleId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
