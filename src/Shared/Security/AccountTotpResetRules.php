<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Regeln, wann ein Akteur das TOTP eines Kontos zuruecksetzen darf (ADR 0002 Nachtrag #16, Ticket #49). Eine Quelle fuer die
 * Ausfuehrung ({@see AccessAdministration::resetTotp()}) und die Lesesicht ({@see AccountAccessView}: gesperrte Aktion mit
 * Begruendung). Pruefreihenfolge: Recht `accounts.reset_mfa` (403 `forbidden`), das eigene Konto (409 `self_totp_reset`), P2 (403
 * `permission_not_held`) im gemeinsamen Kern {@see AccountActionRules}, danach der Status (409 `account_not_active`: aktive und
 * deaktivierte Konten, etwa zur Bereinigung vor einer Reaktivierung; nicht das nur eingeladene). Ob TOTP eingerichtet ist,
 * spielt keine Rolle (G1 ist idempotent). P3 entfaellt: die Zuruecksetzung nimmt keinem Admin die Rolle oder den Status.
 */
final class AccountTotpResetRules
{
    private readonly AccountActionRules $common;

    /** @param string|null $actorId Konto des Akteurs; null = Betreiber (CLI), von P2 ausgenommen wie ein Wildcard-Konto */
    public function __construct(?string $actorId)
    {
        $this->common = new AccountActionRules($actorId, AccountAction::ResetTotp);
    }

    public function refusal(Account $target): ?CodedException
    {
        if (($refusal = $this->common->refusal($target)) !== null) {
            return $refusal;
        }
        return $target->status === AccountStatus::Invited
            ? new CodedException('account_not_active', 409, 'Bei Konten, die noch nicht angenommen wurden, gibt es kein TOTP zurückzusetzen.')
            : null;
    }
}
