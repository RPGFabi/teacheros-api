<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Regeln, wann ein Akteur eine Rolle bearbeiten, loeschen, ihr MFA-Merkmal aendern oder ihr ein Recht hinzufuegen darf
 * (ADR 0002 B4 und Nachtrag #11, Ticket #33): Schutzstufen (409), P2 (403) und das Recht `roles.manage` (403 `forbidden`).
 * Eine Quelle fuer die Ausfuehrung ({@see AccessAdministration}) und die Lesesicht ({@see RoleManagementView}:
 * gesperrte Aktion mit Begruendung), nach dem Muster von {@see RoleAssignmentRules}.
 *
 * Eine Instanz gilt fuer einen Akteur; Rechte und Rollen des Akteurs liest sie einmal frisch aus der Datenbank.
 */
final class RoleManagementRules
{
    public const EDIT = 'edit';
    public const DELETE = 'delete';
    public const SET_MFA = 'set_mfa';

    private readonly ActorPermissions $actor;
    /** @var list<string> */
    private readonly array $heldRoleIds;

    /** @param string|null $actorId Konto des Akteurs; null = Betreiber (CLI), von P2 ausgenommen wie ein Wildcard-Konto */
    public function __construct(?string $actorId)
    {
        $this->actor = new ActorPermissions($actorId);
        $this->heldRoleIds = $actorId === null ? [] : PermissionCatalog::service()->getUserAccess($actorId)['role_ids'];
    }

    /** Warum der Akteur gar keine Rollen anlegen darf (Voraussetzung jeder Aktion), sonst null. */
    public function managementRefusal(): ?CodedException
    {
        return $this->actor->can(PermissionCatalog::ROLES_MANAGE)
            ? null
            : new CodedException('forbidden', 403, 'Dafür brauchen Sie das Recht „Rollen verwalten“.');
    }

    /**
     * Warum der Akteur diese Aktion an der Rolle nicht ausfuehren darf, sonst null.
     *
     * @param array<string,mixed> $role Zeile von {@see RoleOverview::list()}
     * @param self::EDIT|self::DELETE|self::SET_MFA $action
     */
    public function refusal(array $role, string $action): ?CodedException
    {
        if (($refusal = $this->managementRefusal()) !== null) {
            return $refusal;
        }
        $protection = RoleProtection::from($role['protection']);
        return match ($action) {
            self::EDIT => $this->editRefusal($protection),
            self::DELETE => $this->deleteRefusal($role, $protection),
            self::SET_MFA => $this->editRefusal($protection) ?? $this->mfaRefusal($protection),
        };
    }

    /**
     * Warum der Akteur dieses Recht der Rolle nicht hinzufuegen darf, sonst null. Ohne Wildcard (P2) nur ein selbst
     * besessenes Recht, und nie zu einer Rolle, die der Akteur selbst innehat. `$role` null = fuer eine neue Rolle.
     *
     * @param array<string,mixed>|null $role Zeile von {@see RoleOverview::list()}
     */
    public function addPermissionRefusal(?array $role, string $permission): ?CodedException
    {
        if (($refusal = $this->managementRefusal()) !== null) {
            return $refusal;
        }
        if ($role !== null && ($refusal = $this->editRefusal(RoleProtection::from($role['protection']))) !== null) {
            return $refusal;
        }
        if (!$this->actor->isBoundByP2()) {
            return null;
        }
        if ($role !== null && in_array($role['id'], $this->heldRoleIds, true)) {
            return new CodedException('self_escalation', 403, 'Eine Rolle, die Sie selbst innehaben, können Sie nicht erweitern.');
        }
        return $this->actor->can($permission)
            ? null
            : new CodedException('permission_not_held', 403, 'Sie können nur Rechte vergeben, die Sie selbst besitzen.');
    }

    private function editRefusal(RoleProtection $protection): ?CodedException
    {
        return $protection === RoleProtection::Wildcard || $protection === RoleProtection::Protected
            ? new CodedException('role_protected', 409, 'Diese Rolle ist geschützt und lässt sich nicht bearbeiten.')
            : null;
    }

    private function mfaRefusal(RoleProtection $protection): ?CodedException
    {
        return $protection === RoleProtection::Custom
            ? null
            : new CodedException('role_protected', 409, 'Das MFA-Merkmal einer Standardrolle ist fest.');
    }

    /** @param array<string,mixed> $role */
    private function deleteRefusal(array $role, RoleProtection $protection): ?CodedException
    {
        if ($protection !== RoleProtection::Custom) {
            return new CodedException('role_protected', 409, 'Standardrollen lassen sich nicht löschen.');
        }
        return $role['account_count'] > 0 ? new CodedException('role_in_use', 409, 'Die Rolle ist noch Konten zugewiesen.') : null;
    }
}
