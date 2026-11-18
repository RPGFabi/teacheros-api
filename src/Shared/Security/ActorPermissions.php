<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Permissions\EffectivePermissions;

/**
 * Die Rechte des Akteurs einer Aktion und P2 (ADR 0002 B4: nur vergeben, was man selbst besitzt; keine Selbsterweiterung)
 * an EINER Stelle. Ohne Wildcard gilt P2; der Betreiber (CLI, kein Konto) ist davon ausgenommen wie ein Wildcard-Konto.
 * Rechte liest die Instanz frisch aus der Datenbank, einmal bei der Konstruktion.
 */
final class ActorPermissions
{
    private readonly EffectivePermissions $effective;

    /** @param string|null $actorId Konto des Akteurs; null = Betreiber (CLI) */
    public function __construct(public readonly ?string $actorId)
    {
        $this->effective = $actorId === null
            ? new EffectivePermissions(true, [], [])
            : PermissionCatalog::service()->forUser($actorId);
    }

    /** Gilt P2 fuer diesen Akteur (kein Wildcard)? */
    public function isBoundByP2(): bool
    {
        return !$this->effective->hasWildcard;
    }

    public function can(string $permission): bool
    {
        return $this->effective->can($permission);
    }

    /** @param list<string> $permissions */
    public function canAll(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->can($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Besitzt der Akteur alle effektiven Rechte des Kontos (P2 fuer Aktionen am Konto selbst, ohne Wildcard des Akteurs)?
     * Ein Wildcard-Konto hat den ganzen Katalog und mehr; ohne Wildcard besitzt man das nie, daher wirken nur
     * Schuladmins auf Schuladmins.
     */
    public function holdsAllRightsOf(string $accountId): bool
    {
        if (PermissionCatalog::service()->forUser($accountId)->hasWildcard) {
            return false;
        }
        return $this->canAll(PermissionCatalog::effectiveFor($accountId));
    }

    public function isSelf(string $accountId): bool
    {
        return $this->actorId === $accountId;
    }
}
