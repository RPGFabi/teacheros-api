<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Regeln, wann ein Akteur ein Konto deaktivieren darf (ADR 0002 B2 und Nachtrag #12). Eine Quelle fuer die
 * Ausfuehrung ({@see AccessAdministration::deactivateAccount()}) und die Lesesicht ({@see AccountAccessView}:
 * gesperrte Aktion mit Begruendung). Pruefreihenfolge: Recht (403 `forbidden`), sich selbst (409), P2 (403), Status (409),
 * P3 (409).
 *
 * Eine Instanz gilt fuer einen Akteur; Rechte liest sie frisch aus der Datenbank.
 */
final class AccountDeactivationRules
{
    private readonly ActorPermissions $actor;

    /**
     * @param string|null $actorId Konto des Akteurs; null = Betreiber (CLI), von P2 ausgenommen wie ein Wildcard-Konto
     * @param bool $lockAdmins true beim Ausfuehren (in der Transaktion): P3 sperrt den Kreis der Schuladmins ({@see LastAdminGuard});
     *                         false in der Lesesicht, die nichts sperrt
     */
    public function __construct(private readonly ?string $actorId, private readonly bool $lockAdmins = false)
    {
        $this->actor = new ActorPermissions($actorId);
    }

    public function refusal(Account $target): ?CodedException
    {
        if (!$this->actor->can(PermissionCatalog::ACCOUNTS_DEACTIVATE)) {
            return new CodedException('forbidden', 403, 'Dafür fehlt Ihnen das Recht, Konten zu deaktivieren.');
        }
        if ($this->actor->isSelf($target->publicUserId)) {
            return new CodedException('self_deactivation', 409, 'Sie können sich nicht selbst deaktivieren.');
        }
        if ($this->actor->isBoundByP2() && !$this->actor->holdsAllRightsOf($target->publicUserId)) {
            return new CodedException('permission_not_held', 403, 'Sie besitzen nicht alle Rechte dieses Kontos und dürfen es deshalb nicht deaktivieren.');
        }
        if (!$target->isActive()) {
            return new CodedException('account_not_active', 409, 'Nur aktive Konten lassen sich deaktivieren.');
        }
        return (new LastAdminGuard())->refusal([$target->publicUserId], $this->lockAdmins);
    }
}
