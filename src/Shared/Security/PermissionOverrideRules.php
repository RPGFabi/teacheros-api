<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Permissions\UserPermissionService;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Regeln, wann ein Akteur einem Konto ein Einzelrecht gewaehren, entziehen oder zuruecksetzen darf
 * (ADR 0002 B4 und Nachtrag #11, Ticket #36): P2 (alles 403), P6 und "Schuladmin ohne Einzelrechte" (409).
 * Schwesterklasse von {@see RoleAssignmentRules}; eine Quelle fuer die Ausfuehrung ({@see AccessAdministration})
 * und die Lesesicht ({@see AccountAccessView}: gesperrte Aktion mit Begruendung).
 *
 * Eine Instanz gilt fuer einen Akteur und einen Stand der Rollenliste; Rechte liest sie frisch aus der Datenbank.
 */
final class PermissionOverrideRules
{
    /** Einzelrecht gewaehren (Modus `added`; ersetzt ein entzogenes). */
    public const GRANT = 'grant';
    /** Einzelrecht entziehen (Modus `removed`), trotz Rolle. */
    public const WITHDRAW = 'withdraw';
    /** Einzelrecht zuruecksetzen: es gilt wieder, was die Rollen ergeben. */
    public const CLEAR = 'clear';

    private readonly ActorPermissions $actor;
    private readonly UserPermissionService $service;

    /**
     * @param string|null $actorId Konto des Akteurs; null = Betreiber (CLI), von P2 ausgenommen wie ein Wildcard-Konto
     * @param array<string,array<string,mixed>> $rolesById Zeilen von {@see RoleOverview::list()} nach Rollen-ID
     */
    public function __construct(?string $actorId, private readonly array $rolesById)
    {
        $this->service = PermissionCatalog::service();
        $this->actor = new ActorPermissions($actorId);
    }

    /**
     * Warum der Akteur diese Handlung am Konto nicht ausfuehren darf, sonst null. Alle drei Handlungen verlangen,
     * dass der Akteur das Recht selbst effektiv besitzt (das Zuruecksetzen eines entzogenen Einzelrechts gibt das
     * Recht ueber die Rolle zurueck und zaehlt als Gewaehren); sich selbst darf er ohne Wildcard nichts setzen.
     * P6 und "Schuladmin ohne Einzelrechte" sperren nur Gewaehren und Entziehen: Zuruecksetzen raeumt auf.
     *
     * @param self::GRANT|self::WITHDRAW|self::CLEAR $action
     */
    public function refusal(string $targetId, string $permission, string $action): ?CodedException
    {
        if (!in_array($permission, PermissionCatalog::all(), true)) {
            return new CodedException('unknown_permission', 400, 'Dieses Recht gibt es nicht.');
        }
        if ($this->actor->isBoundByP2()) {
            if ($this->actor->isSelf($targetId)) {
                return new CodedException('self_escalation', 403, 'Sie können sich selbst keine Einzelrechte geben, entziehen oder zurücksetzen.');
            }
            if (!$this->actor->can($permission)) {
                return new CodedException('permission_not_held', 403, 'Sie besitzen dieses Recht nicht und dürfen es deshalb weder gewähren noch entziehen oder zurücksetzen.');
            }
        }
        return $action === self::CLEAR ? null : $this->exclusivityRefusal($targetId);
    }

    private function exclusivityRefusal(string $targetId): ?CodedException
    {
        foreach ($this->service->getUserAccess($targetId)['role_ids'] as $roleId) {
            $role = $this->rolesById[$roleId] ?? null;
            if ($role === null) {
                continue;
            }
            if ($role['key'] === StandardRoles::SCHUELER) {
                return new CodedException('student_role_exclusive', 409, 'Ein Konto mit der Rolle Schüler/in hat keine Einzelrechte.');
            }
            if ($role['protection'] === RoleProtection::Wildcard->value) {
                return new CodedException('admin_overrides_forbidden', 409, 'Konten mit der Rolle Schuladmin haben keine Einzelrechte.');
            }
        }
        return null;
    }
}
