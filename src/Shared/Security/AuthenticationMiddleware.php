<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Config\Config;
use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;

/**
 * ADR 0002 B1: JWT lokal pruefen, Konto ueber `sub` laden, Status bei jedem
 * Request frisch pruefen. Ergebnis steht danach in {@see self::current()}.
 */
final class AuthenticationMiddleware
{
    private static ?AuthenticatedSession $current = null;

    public static function current(): AuthenticatedSession
    {
        return self::$current ?? throw new \LogicException('Kein authentifizierter Request');
    }

    public static function handle(Request $request, Response $response): bool
    {
        self::$current = null;
        $jwt = $request->getBearerToken();
        if ($jwt === null) {
            $response->json(['error' => 'Authentication required', 'code' => 'unauthenticated'], 401);
            return false;
        }

        try {
            $token = self::verifier()->verify($jwt);
        } catch (InvalidTokenException) {
            $response->json(['error' => 'Invalid or expired token', 'code' => 'invalid_token'], 401);
            return false;
        }

        $account = (new AccountRepository())->findByPublicUserId($token->subject);
        if ($account === null) {
            $response->json(['error' => 'Unknown account', 'code' => 'unknown_account'], 401);
            return false;
        }
        if (!$account->isActive()) {
            $response->json(['error' => 'Account is not active', 'code' => 'account_inactive'], 401);
            return false;
        }

        self::$current = new AuthenticatedSession($account, $token);
        return true;
    }

    private static function verifier(): AccessTokenVerifier
    {
        $path = Config::getString('AUTH_PUBLIC_KEY_PATH', '');
        $pem = $path !== '' && is_file($path) ? (string) file_get_contents($path) : '';
        // Fehlende Konfiguration => leere Werte => jedes Token wird abgelehnt (fail-closed).
        return new AccessTokenVerifier($pem, Config::getString('AUTH_TOKEN_ISSUER', ''), Config::getString('AUTH_TOKEN_AUDIENCE', ''));
    }
}
