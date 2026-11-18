<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Accounts;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Security\AccountRepository;
use TeacherOS\Shared\Security\AccountStatus;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * HTTP-Endpunkte der Kontenverwaltung (Ticket #26). Rechte pruefen die Routen
 * (`accounts.view`, `accounts.invite`), Fehler mit Code uebersetzt der ApiRouter.
 */
final class AccountsController
{
    public static function index(Request $request, Response $response): void
    {
        $response->json(['accounts' => (new AccountRepository())->listAll()]);
    }

    public static function invite(Request $request, Response $response): void
    {
        $ids = InvitationIdentifiers::from($request->getBody('email'), $request->getBody('login_name'));

        $result = (new AccountInvitations())->invite($ids->email, $ids->loginName, self::actor());
        $response->json(self::payload($result), 201);
    }

    public static function reinvite(Request $request, Response $response): void
    {
        $result = (new AccountInvitations())->reinvite((string) $request->getParam('id'), self::actor());
        $response->json(self::payload($result));
    }

    private static function actor(): Actor
    {
        return Actor::account(AuthenticationMiddleware::current()->account->publicUserId);
    }

    /**
     * `invite_code` und `invite_expires_at` stehen nur im Ergebnis dieses Aufrufs (einmalig) und werden nirgends
     * gespeichert; `null`, wenn der Anmeldedienst sie nicht liefert (kein Code bei Einladung per E-Mail).
     */
    private static function payload(InvitationResult $result): array
    {
        return [
            'account' => [
                'id' => $result->publicUserId,
                'email' => $result->email,
                'login_name' => $result->loginName,
                'status' => AccountStatus::Invited->value,
                'invited_at' => $result->invitedAt,
            ],
            'invite_code' => $result->inviteCode,
            'invite_expires_at' => $result->inviteExpiresAt,
        ];
    }
}
