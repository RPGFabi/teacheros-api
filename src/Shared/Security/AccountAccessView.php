<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Lesesicht auf die Zuweisungen eines Kontos (Kontodetail): Rollen, effektive Rechte mit Herkunft und je Rolle
 * die fuer den Betrachter moegliche Aktion samt Begruendung, wenn sie gesperrt ist.
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
        $roles = array_column((new RoleOverview())->list(), null, 'id');
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
            'account' => [
                'id' => $account->publicUserId,
                'email' => $account->email,
                'login_name' => $account->loginName,
                'status' => $account->status->value,
                'mfa_required' => $account->mfaRequired,
            ],
            'roles' => $assigned,
            'permissions' => $this->permissions($accountId, $roles, $access),
            'role_actions' => $actions,
        ];
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
}
