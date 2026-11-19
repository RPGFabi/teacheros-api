<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Lesesicht der Rollenseite (Ticket #32, Review #11): die Zeilen von {@see RoleOverview::list()} ergaenzt um das,
 * was der Betrachter damit tun darf. Je Rolle `actions` (bearbeiten, loeschen, MFA aendern) und `permission_actions`
 * (ein noch fehlendes Recht hinzufuegen), dazu `create` fuer eine neue Rolle. Jede Aktion nennt `allowed`, bei einem
 * Nein `code` und `reason` aus {@see RoleManagementRules}, derselben Quelle, die die Schreibwege pruefen.
 */
final class RoleManagementView
{
    public function __construct(private readonly RoleOverview $roles = new RoleOverview())
    {
    }

    /**
     * @param string|null $viewerId Betrachter; null = Betreiber
     * @return list<array<string,mixed>>
     */
    public function list(?string $viewerId): array
    {
        $rules = new RoleManagementRules($viewerId);
        return array_map(fn (array $role) => $this->withActions($role, $rules), $this->roles->list());
    }

    /**
     * @param string|null $viewerId Betrachter; null = Betreiber
     * @return array<string,mixed>|null
     */
    public function find(string $roleId, ?string $viewerId): ?array
    {
        $role = $this->roles->find($roleId);
        return $role === null ? null : $this->withActions($role, new RoleManagementRules($viewerId));
    }

    /**
     * Anlegen einer neuen Rolle: darf der Betrachter das, und welche Rechte darf er ihr geben?
     *
     * @return array<string,mixed>
     */
    public function creation(?string $viewerId): array
    {
        $rules = new RoleManagementRules($viewerId);
        return $this->outcome($rules->managementRefusal()) + [
            'permission_actions' => array_map(
                fn (array $entry) => ['permission' => $entry['key']] + $this->outcome($rules->addPermissionRefusal(null, $entry['key'])),
                PermissionCatalog::entries(),
            ),
        ];
    }

    /**
     * @param array<string,mixed> $role
     * @return array<string,mixed>
     */
    private function withActions(array $role, RoleManagementRules $rules): array
    {
        $actions = [];
        foreach ([RoleManagementRules::EDIT, RoleManagementRules::DELETE, RoleManagementRules::SET_MFA] as $action) {
            $actions[] = ['action' => $action] + $this->outcome($rules->refusal($role, $action));
        }
        $permissionActions = [];
        foreach (PermissionCatalog::entries() as $entry) {
            if (!in_array($entry['key'], $role['permissions'], true)) {
                $permissionActions[] = ['permission' => $entry['key']] + $this->outcome($rules->addPermissionRefusal($role, $entry['key']));
            }
        }
        return $role + ['actions' => $actions, 'permission_actions' => $permissionActions];
    }

    /** @return array{allowed:bool,code:?string,reason:?string} */
    private function outcome(?CodedException $refusal): array
    {
        return ['allowed' => $refusal === null, 'code' => $refusal?->errorCode, 'reason' => $refusal?->getMessage()];
    }
}
