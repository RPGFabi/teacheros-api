<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Accounts;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AccountRepository;
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
        $email = self::text($request->getBody('email'));
        $loginName = self::text($request->getBody('login_name'));
        if ($email === null) {
            // Einladung nur per Anmeldename ohne E-Mail kommt mit Ticket #28.
            throw new CodedException('email_required', 400, 'Eine E-Mail-Adresse ist erforderlich.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CodedException('invalid_email', 400, 'Die E-Mail-Adresse ist ungueltig.');
        }

        $result = (new AccountInvitations())->invite(strtolower($email), $loginName === null ? null : strtolower($loginName), self::actor());
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

    /** `invite_code` steht nur im Ergebnis dieses Aufrufs (einmalig) und wird nirgends gespeichert. */
    private static function payload(InvitationResult $result): array
    {
        $repository = new AccountRepository();
        $account = $repository->findByPublicUserId($result->publicUserId);
        return [
            'account' => [
                'id' => $result->publicUserId,
                'email' => $result->email,
                'login_name' => $result->loginName,
                'status' => $account?->status ?? 'invited',
                'invited_at' => $repository->invitedAt($result->publicUserId),
            ],
            'invite_code' => $result->inviteCode,
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return trim($value);
    }
}
