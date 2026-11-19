<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Der gemeinsame Kern der Regeln fuer Aktionen am Konto (ADR 0002 B2 und Nachtrag #12): das Recht der Aktion
 * (403 `forbidden`; `accounts.deactivate`, `accounts.revoke_sessions`), nicht an sich selbst (409 `self_deactivation` / `self_reactivation` / `self_revocation`) und P2 (403 `permission_not_held`),
 * samt Meldungstexten. {@see AccountDeactivationRules} und {@see AccountReactivationRules} setzen Status und weitere Regeln
 * obendrauf; die Lesesicht ({@see AccountAccessView}) baut keine eigenen Meldungen.
 *
 * Eine Instanz gilt fuer einen Akteur und eine Aktion; Rechte liest sie frisch aus der Datenbank.
 */
final class AccountActionRules
{
    private readonly ActorPermissions $actor;

    /** @param string|null $actorId Konto des Akteurs; null = Betreiber (CLI), von P2 ausgenommen wie ein Wildcard-Konto */
    public function __construct(?string $actorId, private readonly AccountAction $action)
    {
        $this->actor = new ActorPermissions($actorId);
    }

    /**
     * Nur das Recht (ohne Blick auf ein Zielkonto), z. B. fuer "Abgleich wiederholen". Das wiederholt, wer eines der
     * Konto-Rechte hat ({@see PermissionCatalog::ACCOUNT_ACTION_RIGHTS}); die uebrigen Aktionen verlangen ihr eigenes Recht
     * ({@see AccountAction::right()}).
     */
    public function rightRefusal(): ?CodedException
    {
        $right = $this->action->right();
        $held = $right === null
            ? array_filter(PermissionCatalog::ACCOUNT_ACTION_RIGHTS, $this->actor->can(...)) !== []
            : $this->actor->can($right);
        return $held ? null : new CodedException('forbidden', 403, $this->action->missingRightMessage());
    }

    /** Pruefreihenfolge: Recht, sich selbst, P2. */
    public function refusal(Account $target): ?CodedException
    {
        if (($refusal = $this->rightRefusal()) !== null) {
            return $refusal;
        }
        if ($this->actor->isSelf($target->publicUserId)) {
            return new CodedException($this->action->selfCode(), 409, $this->action->selfMessage());
        }
        if ($this->actor->isBoundByP2() && !$this->actor->holdsAllRightsOf($target->publicUserId)) {
            return new CodedException('permission_not_held', 403, $this->action->permissionNotHeldMessage());
        }
        return null;
    }
}
