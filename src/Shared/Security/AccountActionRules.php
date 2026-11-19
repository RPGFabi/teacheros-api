<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Der gemeinsame Kern der Regeln fuer Aktionen am Konto (ADR 0002 B2 und Nachtrag #12): das Recht `accounts.deactivate`
 * (403 `forbidden`), nicht an sich selbst (409 `self_deactivation` / `self_reactivation`) und P2 (403 `permission_not_held`),
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
     * Konto-Rechte hat ({@see PermissionCatalog::ACCOUNT_ACTION_RIGHTS}); die uebrigen Aktionen verlangen `accounts.deactivate`.
     */
    public function rightRefusal(): ?CodedException
    {
        $held = $this->action === AccountAction::ResyncLogin
            ? array_filter(PermissionCatalog::ACCOUNT_ACTION_RIGHTS, $this->actor->can(...)) !== []
            : $this->actor->can(PermissionCatalog::ACCOUNTS_DEACTIVATE);
        return $held
            ? null
            : new CodedException('forbidden', 403, $this->action === AccountAction::ResyncLogin
                ? 'Dafür fehlt Ihnen das Recht, den Abgleich mit dem Anmeldedienst zu wiederholen.'
                : 'Dafür fehlt Ihnen das Recht, Konten zu ' . $this->action->verb() . '.');
    }

    /** Pruefreihenfolge: Recht, sich selbst, P2. */
    public function refusal(Account $target): ?CodedException
    {
        $verb = $this->action->verb();
        if (($refusal = $this->rightRefusal()) !== null) {
            return $refusal;
        }
        if ($this->actor->isSelf($target->publicUserId)) {
            return new CodedException($this->action->selfCode(), 409, "Sie können sich nicht selbst $verb.");
        }
        if ($this->actor->isBoundByP2() && !$this->actor->holdsAllRightsOf($target->publicUserId)) {
            return new CodedException('permission_not_held', 403, "Sie besitzen nicht alle Rechte dieses Kontos und dürfen es deshalb nicht $verb.");
        }
        return null;
    }
}
