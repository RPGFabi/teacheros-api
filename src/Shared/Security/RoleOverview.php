<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Permissions\PermissionStore;

/**
 * Lesesicht auf die Rollen der Schulinstanz: Paket (Rolle, Rechte, Anzahl Konten) plus
 * Merkmale der Anwendung (Schutzstufe, MFA). Wildcard-Rollen erscheinen als "alle Rechte";
 * `*` verlaesst die API nie.
 */
final class RoleOverview
{
    public function __construct(private readonly RoleSettingsRepository $settings = new RoleSettingsRepository())
    {
    }

    /**
     * Standardrollen in fester Reihenfolge, danach eigene Rollen nach Name.
     *
     * @return list<array{id:string,key:string,name:string,description:?string,permissions:list<string>,all_permissions:bool,requires_mfa:bool,protection:string,account_count:int}>
     */
    public function list(): array
    {
        $settings = $this->settings->all();
        $roles = [];
        foreach (PermissionCatalog::service()->listRoles() as $role) {
            $own = $settings[$role['id']] ?? throw new \LogicException("Die Rolle {$role['id']} hat keine Merkmale in role_settings.");
            $all = in_array(PermissionStore::WILDCARD_PERMISSION, $role['permissions'], true);
            $roles[] = [
                'id' => $role['id'],
                'key' => $role['role_key'],
                'name' => $role['name'],
                'description' => $role['description'],
                'permissions' => $all ? PermissionCatalog::all() : array_values($role['permissions']),
                'all_permissions' => $all,
                'requires_mfa' => $own['requires_mfa'],
                'protection' => $own['protection']->value,
                'account_count' => $role['user_count'],
            ];
        }
        usort($roles, static fn (array $a, array $b) => [StandardRoles::position($a['key']), $a['name']] <=> [StandardRoles::position($b['key']), $b['name']]);
        return $roles;
    }

    /** @return array<string,mixed>|null eine Zeile von {@see self::list()} */
    public function find(string $roleId): ?array
    {
        foreach ($this->list() as $role) {
            if ($role['id'] === $roleId) {
                return $role;
            }
        }
        return null;
    }
}
