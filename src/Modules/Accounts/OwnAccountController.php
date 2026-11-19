<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Accounts;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AuthenticationMiddleware;
use TeacherOS\Shared\Security\RevocationInitiator;
use TeacherOS\Shared\Security\SessionRevocation;

/** Aktionen eines Kontos an sich selbst ("Mein Konto"), ohne besonderes Recht. */
final class OwnAccountController
{
    /** Anzahl der sofortigen Versuche beim Anmeldedienst (ADR 0002 Nachtrag #16). */
    private const REVOCATION_ATTEMPTS = 3;

    /**
     * Eigener Sitzungswiderruf (Ticket #50): beendet alle Sitzungen des angemeldeten Kontos, auch die aktuelle. Wie der Widerruf
     * der Verwaltung ({@see SessionRevocation}), aber mit bis zu drei sofortigen Versuchen bei G3. Scheitern alle, bleibt der
     * Widerruf offen (jedes Token des Kontos wird abgelehnt, das erste Token einer neuen Anmeldung holt ihn nach) und die Antwort
     * ist 502 `auth_unavailable`. Antwort bei Erfolg: `{"revoked": true}`.
     */
    public static function revokeSessions(Request $request, Response $response): void
    {
        $accountId = AuthenticationMiddleware::current()->account->publicUserId;
        $completed = (new SessionRevocation())->revoke(Actor::account($accountId), $accountId, RevocationInitiator::Owner, self::REVOCATION_ATTEMPTS);
        if (!$completed) {
            throw new CodedException('auth_unavailable', 502, 'Der Anmeldedienst ist nicht erreichbar. Alle Sitzungen sind gesperrt; bitte spaeter erneut anmelden oder an die Schule wenden.');
        }
        $response->json(['revoked' => true]);
    }
}
