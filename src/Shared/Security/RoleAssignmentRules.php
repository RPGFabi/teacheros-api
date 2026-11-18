<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Permissions\EffectivePermissions;
use Hoelzle\Permissions\UserPermissionService;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Regeln, wann ein Akteur einem Konto eine Rolle vergeben oder entziehen darf (ADR 0002 B4 und Nachtrag #11):
 * P2 (symmetrisch, alles 403) und P6 samt "Schuladmin ohne Einzelrechte" (409). Eine Quelle fuer die Ausfuehrung
 * ({@see AccessAdministration}) und die Lesesicht ({@see AccountAccessView}: gesperrte Aktion mit Begruendung).
 *
 * Eine Instanz gilt fuer einen Akteur und einen Stand der Rollenliste; Rechte liest sie frisch aus der Datenbank.
 */
final class RoleAssignmentRules
{
    private readonly EffectivePermissions $actor;
    private readonly UserPermissionService $service;

    /**
     * @param string|null $actorId Konto des Akteurs; null = Betreiber (CLI), von P2 ausgenommen wie ein Wildcard-Konto
     * @param array<string,array<string,mixed>> $rolesById Zeilen von {@see RoleOverview::list()} nach Rollen-ID
     */
    public function __construct(private readonly ?string $actorId, private readonly array $rolesById)
    {
        $this->service = PermissionCatalog::service();
        $this->actor = $actorId === null
            ? new EffectivePermissions(true, [], [])
            : $this->service->forUser($actorId);
    }

    /**
     * Warum der Akteur diese Rolle dem Konto nicht vergeben ($assign) bzw. nicht entziehen darf, sonst null.
     * Vergeben einer schon vorhandenen Rolle ist kein Verstoss (es geschieht nichts).
     *
     * @param array<string,mixed> $role Zeile von {@see RoleOverview::list()}
     */
    public function refusal(string $targetId, array $role, bool $assign): ?CodedException
    {
        if (!$this->actor->hasWildcard) {
            if ($assign && $targetId === $this->actorId) {
                return new CodedException('self_escalation', 403, 'Sie können sich selbst keine Rolle geben.');
            }
            if (!$this->actorHoldsAll($role)) {
                return new CodedException('permission_not_held', 403, 'Sie besitzen nicht alle Rechte dieser Rolle und dürfen sie deshalb weder vergeben noch entziehen.');
            }
        }
        if (!$assign) {
            // Andockpunkt Ticket #35 (P3): Entziehen der Schuladmin-Rolle vom letzten aktiven Schuladmin -> `last_admin`.
            return null;
        }
        return $this->exclusivityRefusal($targetId, $role);
    }

    /** @param array<string,mixed> $role */
    private function actorHoldsAll(array $role): bool
    {
        if ($role['all_permissions']) {
            return false;
        }
        foreach ($role['permissions'] as $permission) {
            if (!$this->actor->can($permission)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $role */
    private function exclusivityRefusal(string $targetId, array $role): ?CodedException
    {
        $access = $this->service->getUserAccess($targetId);
        if (in_array($role['id'], $access['role_ids'], true)) {
            return null;
        }
        $heldKeys = array_map(fn (string $id) => $this->rolesById[$id]['key'] ?? '', $access['role_ids']);
        $student = new CodedException('student_role_exclusive', 409, 'Ein Konto mit der Rolle Schüler/in hat keine weitere Rolle und keine Einzelrechte.');

        if ($role['key'] === StandardRoles::SCHUELER) {
            return $access['role_ids'] !== [] || $access['overrides'] !== [] ? $student : null;
        }
        if (in_array(StandardRoles::SCHUELER, $heldKeys, true)) {
            return $student;
        }
        if ($role['protection'] === RoleProtection::Wildcard->value && $access['overrides'] !== []) {
            return new CodedException('admin_overrides_forbidden', 409, 'Konten mit Einzelrechten können nicht Schuladmin werden. Setzen Sie die Einzelrechte zuerst zurück.');
        }
        return null;
    }
}
