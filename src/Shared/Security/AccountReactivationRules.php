<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Regeln, wann ein Akteur ein Konto reaktivieren darf (ADR 0002 B2 und Nachtrag #12, Ticket #43): Schwesterregel zu
 * {@see AccountDeactivationRules}. Eine Quelle fuer die Ausfuehrung ({@see AccessAdministration::reactivateAccount()})
 * und die Lesesicht ({@see AccountAccessView}: gesperrte Aktion mit Begruendung). Pruefreihenfolge: Recht (403 `forbidden`),
 * sich selbst (409), P2 (403 `permission_not_held`), Status (409 `account_not_inactive`). P3 entfaellt: Reaktivieren
 * verringert den Kreis der Schuladmins nie.
 *
 * Eine Instanz gilt fuer einen Akteur; Rechte liest sie frisch aus der Datenbank.
 */
final class AccountReactivationRules
{
    private readonly ActorPermissions $actor;

    /** @param string|null $actorId Konto des Akteurs; null = Betreiber (CLI), von P2 ausgenommen wie ein Wildcard-Konto */
    public function __construct(?string $actorId)
    {
        $this->actor = new ActorPermissions($actorId);
    }

    public function refusal(Account $target): ?CodedException
    {
        if (!$this->actor->can(PermissionCatalog::ACCOUNTS_DEACTIVATE)) {
            return new CodedException('forbidden', 403, 'Dafür fehlt Ihnen das Recht, Konten zu reaktivieren.');
        }
        if ($this->actor->isSelf($target->publicUserId)) {
            return new CodedException('self_reactivation', 409, 'Sie können sich nicht selbst reaktivieren.');
        }
        if ($this->actor->isBoundByP2() && !$this->actor->holdsAllRightsOf($target->publicUserId)) {
            return new CodedException('permission_not_held', 403, 'Sie besitzen nicht alle Rechte dieses Kontos und dürfen es deshalb nicht reaktivieren.');
        }
        if ($target->status !== AccountStatus::Inactive) {
            return new CodedException('account_not_inactive', 409, 'Nur deaktivierte Konten lassen sich reaktivieren.');
        }
        return null;
    }
}
