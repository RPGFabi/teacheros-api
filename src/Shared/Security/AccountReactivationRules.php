<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Regeln, wann ein Akteur ein Konto reaktivieren darf (ADR 0002 B2 und Nachtrag #12, Ticket #43): Schwesterregel zu
 * {@see AccountDeactivationRules}. Eine Quelle fuer die Ausfuehrung ({@see AccessAdministration::reactivateAccount()})
 * und die Lesesicht ({@see AccountAccessView}: gesperrte Aktion mit Begruendung). Pruefreihenfolge: Recht (403 `forbidden`),
 * sich selbst (409), P2 (403 `permission_not_held`) im gemeinsamen Kern {@see AccountActionRules}, Status (409 `account_not_inactive`). P3 entfaellt: Reaktivieren
 * verringert den Kreis der Schuladmins nie.
 *
 * Eine Instanz gilt fuer einen Akteur; Rechte liest sie frisch aus der Datenbank.
 */
final class AccountReactivationRules
{
    private readonly AccountActionRules $common;

    /** @param string|null $actorId Konto des Akteurs; null = Betreiber (CLI), von P2 ausgenommen wie ein Wildcard-Konto */
    public function __construct(?string $actorId)
    {
        $this->common = new AccountActionRules($actorId, AccountAction::Reactivate);
    }

    public function refusal(Account $target): ?CodedException
    {
        if (($refusal = $this->common->refusal($target)) !== null) {
            return $refusal;
        }
        return $target->status === AccountStatus::Inactive
            ? null
            : new CodedException('account_not_inactive', 409, 'Nur deaktivierte Konten lassen sich reaktivieren.');
    }
}
