<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Regeln, wann ein Akteur ein Konto deaktivieren darf (ADR 0002 B2 und Nachtrag #12). Eine Quelle fuer die
 * Ausfuehrung ({@see AccessAdministration::deactivateAccount()}) und die Lesesicht ({@see AccountAccessView}:
 * gesperrte Aktion mit Begruendung). Pruefreihenfolge: Recht (403 `forbidden`), sich selbst (409), P2 (403), Status (409),
 * P3 (409); die ersten drei stehen im gemeinsamen Kern {@see AccountActionRules}.
 *
 * Eine Instanz gilt fuer einen Akteur; Rechte liest sie frisch aus der Datenbank.
 */
final class AccountDeactivationRules
{
    private readonly AccountActionRules $common;

    /**
     * @param string|null $actorId Konto des Akteurs; null = Betreiber (CLI), von P2 ausgenommen wie ein Wildcard-Konto
     * @param bool $lockAdmins true beim Ausfuehren (in der Transaktion): P3 sperrt den Kreis der Schuladmins ({@see LastAdminGuard});
     *                         false in der Lesesicht, die nichts sperrt
     */
    public function __construct(?string $actorId, private readonly bool $lockAdmins = false)
    {
        $this->common = new AccountActionRules($actorId, AccountAction::Deactivate);
    }

    public function refusal(Account $target): ?CodedException
    {
        if (($refusal = $this->common->refusal($target)) !== null) {
            return $refusal;
        }
        if (!$target->isActive()) {
            return new CodedException('account_not_active', 409, 'Nur aktive Konten lassen sich deaktivieren.');
        }
        return (new LastAdminGuard())->refusal([$target->publicUserId], $this->lockAdmins);
    }
}
