<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Lesesicht auf die Zuweisungen eines Kontos (Kontodetail): Rollen, effektive Rechte mit Herkunft und je Rolle
 * die fuer den Betrachter moegliche Aktion samt Begruendung, wenn sie gesperrt ist; ebenso je Katalogrecht die
 * moegliche Einzelrecht-Aktion (`permission_actions`, Ticket #36).
 *
 * Herkunft (`sources`) eines Rechts: `role` (mit Rolle), `individual` (Einzelrecht gewaehrt) oder `withdrawn`
 * (Einzelrecht entzogen; das Recht gilt dann nicht, `effective` ist false). Rechte ohne Herkunft fehlen.
 */
final class AccountAccessView
{
    public function __construct(private readonly AccountRepository $accounts = new AccountRepository())
    {
    }

    /**
     * @param string|null $viewerId Betrachter fuer `role_actions`; null = Betreiber
     * @throws CodedException `account_not_found` (404)
     * @return array<string,mixed>
     */
    public function for(string $accountId, ?string $viewerId): array
    {
        $account = $this->accounts->findByPublicUserId($accountId)
            ?? throw new CodedException('account_not_found', 404, 'Das Konto gibt es nicht.');
        $access = PermissionCatalog::service()->getUserAccess($accountId);
        $roles = (new RoleOverview())->byId();
        $held = array_flip($access['role_ids']);

        $rules = new RoleAssignmentRules($viewerId, $roles);
        $actions = [];
        $assigned = [];
        foreach ($roles as $id => $role) {
            $isHeld = isset($held[$id]);
            $refusal = $rules->refusal($accountId, $role, !$isHeld);
            $actions[] = [
                'role_id' => $id,
                'role_key' => $role['key'],
                'role_name' => $role['name'],
                'action' => $isHeld ? 'revoke' : 'assign',
                'allowed' => $refusal === null,
                'code' => $refusal?->errorCode,
                'reason' => $refusal?->getMessage(),
            ];
            if ($isHeld) {
                $assigned[] = ['id' => $id, 'key' => $role['key'], 'name' => $role['name'], 'protection' => $role['protection'], 'requires_mfa' => $role['requires_mfa']];
            }
        }

        return [
            'account' => $this->accountData($account, $viewerId),
            'roles' => $assigned,
            'permissions' => $this->permissions($accountId, $roles, $access),
            'role_actions' => $actions,
            'permission_actions' => $this->permissionActions($accountId, $viewerId, $roles, $access),
            'account_actions' => $this->accountActions($account, $viewerId),
        ];
    }

    /**
     * E-Mail, Anmeldename und Status nur fuer Betrachter mit `accounts.view` (oder den Betreiber): die Schreibwege
     * (`roles.assign`, `permissions.assign`) geben dieselbe Lesesicht zurueck, verlangen aber nicht `accounts.view`.
     *
     * @return array<string,mixed>
     */
    private function accountData(Account $account, ?string $viewerId): array
    {
        $data = ['id' => $account->publicUserId];
        if ($viewerId === null || PermissionCatalog::service()->canAny($viewerId, [PermissionCatalog::ACCOUNTS_VIEW])) {
            $data += [
                'email' => $account->email,
                'login_name' => $account->loginName,
                'status' => $account->status->value,
                'inactive_since' => $account->inactiveSince === null ? null : gmdate('c', $account->inactiveSince),
                'inactive_reason' => $account->inactiveReason,
                'login_sync_pending' => $account->loginSyncPending,
            ];
        }
        return $data + ['mfa_required' => $account->mfaRequired];
    }

    /**
     * Die Aktionen am Konto selbst fuer den Betrachter (Ticket #41): `deactivate`, mit `allowed`, bei Sperre
     * `code` und `reason` aus {@see AccountDeactivationRules}; dazu `resync_login` ("Abgleich wiederholen", Ticket #42),
     * nur solange der Abgleich offen ist, erlaubt mit `accounts.deactivate`. Ticket #43 ergaenzt `reactivate`.
     *
     * @return list<array{action:string,allowed:bool,code:?string,reason:?string}>
     */
    private function accountActions(Account $account, ?string $viewerId): array
    {
        $refusal = (new AccountDeactivationRules($viewerId))->refusal($account);
        $actions = [['action' => 'deactivate', 'allowed' => $refusal === null, 'code' => $refusal?->errorCode, 'reason' => $refusal?->getMessage()]];
        if (!$account->isActive() && $account->loginSyncPending) {
            $allowed = (new ActorPermissions($viewerId))->can(PermissionCatalog::ACCOUNTS_DEACTIVATE);
            $actions[] = ['action' => 'resync_login', 'allowed' => $allowed, 'code' => $allowed ? null : 'forbidden', 'reason' => $allowed ? null : 'Dafür fehlt Ihnen das Recht, Konten zu deaktivieren.'];
        }
        return $actions;
    }

    /**
     * @param array<string,array<string,mixed>> $roles
     * @param array{role_ids:list<string>,overrides:array<string,string>} $access
     * @return list<array{key:string,name:string,group:string,effective:bool,sources:list<array<string,string>>}>
     */
    private function permissions(string $accountId, array $roles, array $access): array
    {
        $effective = PermissionCatalog::service()->forUser($accountId);
        $sources = [];
        foreach ($access['role_ids'] as $roleId) {
            $role = $roles[$roleId] ?? null;
            if ($role === null) {
                continue;
            }
            foreach ($role['permissions'] as $permission) {
                $sources[$permission][] = ['kind' => 'role', 'role_id' => $roleId, 'role_key' => $role['key'], 'role_name' => $role['name']];
            }
        }
        foreach ($access['overrides'] as $permission => $mode) {
            $sources[$permission][] = ['kind' => $mode === 'added' ? 'individual' : 'withdrawn'];
        }

        $result = [];
        foreach (PermissionCatalog::entries() as $entry) {
            if (isset($sources[$entry['key']])) {
                $result[] = $entry + ['effective' => $effective->can($entry['key']), 'sources' => $sources[$entry['key']]];
            }
        }
        return $result;
    }

    /**
     * Je Katalogrecht die eine fuer den Betrachter moegliche Einzelrecht-Aktion (Ticket #36): `clear`, wenn das
     * Konto ein Einzelrecht (gewaehrt oder entzogen) dazu hat, sonst `withdraw`, wenn es das Recht effektiv hat,
     * sonst `grant`. Gesperrte Aktionen nennen `code` und `reason` aus {@see PermissionOverrideRules}.
     *
     * @param array<string,array<string,mixed>> $roles
     * @param array{role_ids:list<string>,overrides:array<string,string>} $access
     * @return list<array{permission:string,name:string,group:string,action:string,allowed:bool,code:?string,reason:?string}>
     */
    private function permissionActions(string $accountId, ?string $viewerId, array $roles, array $access): array
    {
        $effective = PermissionCatalog::service()->forUser($accountId);
        $rules = new PermissionOverrideRules($viewerId, $roles);
        $result = [];
        foreach (PermissionCatalog::entries() as $entry) {
            $action = isset($access['overrides'][$entry['key']])
                ? PermissionOverrideRules::CLEAR
                : ($effective->can($entry['key']) ? PermissionOverrideRules::WITHDRAW : PermissionOverrideRules::GRANT);
            $refusal = $rules->refusal($accountId, $entry['key'], $action);
            $result[] = [
                'permission' => $entry['key'],
                'name' => $entry['name'],
                'group' => $entry['group'],
                'action' => $action,
                'allowed' => $refusal === null,
                'code' => $refusal?->errorCode,
                'reason' => $refusal?->getMessage(),
            ];
        }
        return $result;
    }
}
