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
        $view = ['account' => $this->accountData($account, $viewerId)];
        if ($this->mayAdministerAccess($viewerId)) {
            $view += $this->accessSections($accountId, $viewerId);
        }
        return $view + ['account_actions' => $this->accountActions($account, $viewerId)];
    }

    /**
     * Rollen und Rechte zeigt die Lesesicht nur, wer eines der drei Verwaltungsrechte hat (oder der Betreiber): wer nur
     * Konten deaktiviert (Review #12), sieht das Konto und die Kontoaktionen.
     */
    private function mayAdministerAccess(?string $viewerId): bool
    {
        return $viewerId === null || PermissionCatalog::service()->canAny($viewerId, PermissionCatalog::ADMINISTRATION);
    }

    /**
     * Die offenen Aufrufe an den Anmeldedienst ("Abgleich offen", Ticket #47) in der festen Reihenfolge des Nachholens,
     * je mit dem Code der letzten fachlichen Ablehnung (null: nicht erreichbar oder noch nicht versucht). Wie E-Mail und
     * Status nur fuer Betrachter mit `accounts.view`.
     *
     * @return list<array{operation:string,rejection_code:?string}>
     */
    private function openCalls(Account $account): array
    {
        return array_map(
            static fn (OpenCall $call) => ['operation' => $call->operation, 'rejection_code' => $call->rejectionCode],
            (new OpenCalls())->ordered($account->openCalls),
        );
    }

    private function mayReadAccount(?string $viewerId): bool
    {
        return $viewerId === null || PermissionCatalog::service()->canAny($viewerId, [PermissionCatalog::ACCOUNTS_VIEW]);
    }

    /** @return array<string,mixed> `roles`, `permissions`, `role_actions`, `permission_actions` */
    private function accessSections(string $accountId, ?string $viewerId): array
    {
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
            'roles' => $assigned,
            'permissions' => $this->permissions($accountId, $roles, $access),
            'role_actions' => $actions,
            'permission_actions' => $this->permissionActions($accountId, $viewerId, $roles, $access),
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
        if ($this->mayReadAccount($viewerId)) {
            $data += [
                'email' => $account->email,
                'login_name' => $account->loginName,
                'status' => $account->status->value,
                'inactive_since' => $account->inactiveSince === null ? null : gmdate('c', $account->inactiveSince),
                'inactive_reason' => $account->inactiveReason?->value,
                'login_sync_pending' => $account->openCall(DisableLoginCall::OPERATION) !== null,
                'login_sync_error' => $account->openCall(DisableLoginCall::OPERATION)?->rejectionCode,
                'open_calls' => $this->openCalls($account),
            ];
        }
        return $data + ['mfa_required' => $account->mfaRequired];
    }

    /**
     * Die Aktionen am Konto selbst fuer den Betrachter ({@see AccountAction}), je mit `allowed`, bei Sperre `code` und
     * `reason` aus den Regeln: `deactivate` (Ticket #41, {@see AccountDeactivationRules}); `resync_login` ("Abgleich wiederholen",
     * Tickets #42, #47) nur solange ein Aufruf offen ist, erlaubt mit einem der Konto-Rechte ({@see PermissionCatalog::ACCOUNT_ACTION_RIGHTS}); `reactivate` (Ticket #43,
     * {@see AccountReactivationRules}) nur bei deaktivierten Konten.
     *
     * @return list<array{action:string,allowed:bool,code:?string,reason:?string}>
     */
    private function accountActions(Account $account, ?string $viewerId): array
    {
        $actions = [$this->accountAction(AccountAction::Deactivate, (new AccountDeactivationRules($viewerId))->refusal($account))];
        if ($account->openCalls !== []) {
            $actions[] = $this->accountAction(AccountAction::ResyncLogin, (new AccountActionRules($viewerId, AccountAction::ResyncLogin))->rightRefusal());
        }
        if ($account->status === AccountStatus::Inactive) {
            $actions[] = $this->accountAction(AccountAction::Reactivate, (new AccountReactivationRules($viewerId))->refusal($account));
        }
        return $actions;
    }

    /** @return array{action:string,allowed:bool,code:?string,reason:?string} */
    private function accountAction(AccountAction $action, ?CodedException $refusal): array
    {
        return ['action' => $action->value, 'allowed' => $refusal === null, 'code' => $refusal?->errorCode, 'reason' => $refusal?->getMessage()];
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
