<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Regeln, wann ein Akteur die Sitzungen eines Kontos beenden darf (ADR 0002 Nachtrag #16, Ticket #48). Eine Quelle fuer
 * die Ausfuehrung ({@see AccessAdministration::revokeSessions()}) und die Lesesicht ({@see AccountAccessView}: gesperrte Aktion
 * mit Begruendung). Pruefreihenfolge: Recht `accounts.revoke_sessions` (403 `forbidden`), das eigene Konto (409 `self_revocation`),
 * P2 (403 `permission_not_held`) im gemeinsamen Kern {@see AccountActionRules}, danach der Status (409 `account_not_active`: nur aktive Konten).
 * P3 entfaellt: der Widerruf nimmt keinem Admin die Rolle oder den Status.
 *
 * Der eigene Widerruf ("Mein Konto", Ticket #50) braucht diese Regeln nicht und ruft {@see SessionRevocation} direkt.
 */
final class AccountRevocationRules
{
    private readonly AccountActionRules $common;

    /** @param string|null $actorId Konto des Akteurs; null = Betreiber (CLI), von P2 ausgenommen wie ein Wildcard-Konto */
    public function __construct(?string $actorId)
    {
        $this->common = new AccountActionRules($actorId, AccountAction::RevokeSessions);
    }

    public function refusal(Account $target): ?CodedException
    {
        if (($refusal = $this->common->refusal($target)) !== null) {
            return $refusal;
        }
        return $target->isActive()
            ? null
            : new CodedException('account_not_active', 409, 'Nur bei aktiven Konten lassen sich die Sitzungen beenden.');
    }
}
