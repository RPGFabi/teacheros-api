<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Accounts;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AccessAdministration;
use TeacherOS\Shared\Security\AccountAccessView;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Zuweisungen eines Kontos (Ticket #34): Kontodetail lesen (eines der drei Verwaltungsrechte), Rollen einzeln
 * vergeben und entziehen (`roles.assign`, das prueft die Route). Fehler mit Code uebersetzt der ApiRouter.
 * Alle Aenderungen laufen ueber {@see AccessAdministration}; jede Antwort ist die aktuelle Lesesicht.
 */
final class AccountAccessController
{
    public static function show(Request $request, Response $response): void
    {
        $response->json(self::view((string) $request->getParam('id')));
    }

    public static function assign(Request $request, Response $response): void
    {
        $roleId = $request->getBody('role_id');
        if (!is_string($roleId) || trim($roleId) === '') {
            throw new CodedException('role_required', 400, 'Bitte eine Rolle angeben.');
        }
        $accountId = (string) $request->getParam('id');
        (new AccessAdministration())->assignRole(self::actor(), $accountId, trim($roleId));
        $response->json(self::view($accountId));
    }

    public static function revoke(Request $request, Response $response): void
    {
        $accountId = (string) $request->getParam('id');
        (new AccessAdministration())->revokeRole(self::actor(), $accountId, (string) $request->getParam('roleId'));
        $response->json(self::view($accountId));
    }

    /** @return array<string,mixed> */
    private static function view(string $accountId): array
    {
        return (new AccountAccessView())->for($accountId, AuthenticationMiddleware::current()->account->publicUserId);
    }

    private static function actor(): Actor
    {
        return Actor::account(AuthenticationMiddleware::current()->account->publicUserId);
    }
}
