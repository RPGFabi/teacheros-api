<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Application;
use Hoelzle\Core\Config\Config;
use Hoelzle\Core\Database\DB;
use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;

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

    /** Der angemeldete Account als Akteur auditierter Handlungen. */
    public static function actor(): Actor
    {
        return Actor::account(self::current()->account->publicUserId);
    }

    /** Fachliche Routen: zusaetzlich zur Anmeldung gilt die MFA-Pflicht (ADR 0002 B3). */
    public static function handle(Request $request, Response $response): bool
    {
        if (!self::authenticate($request, $response)) {
            return false;
        }
        $session = self::current();
        if ($session->account->mfaRequired && !$session->token->usedMfa) {
            // B3: Konto mit Pflichtrolle, aber ohne zweiten Faktor angemeldet. Das Web fuehrt dann durch die Einrichtung.
            self::$current = null;
            $response->json(['error' => 'MFA enrollment required', 'code' => 'mfa_enrollment_required'], 403);
            return false;
        }
        return true;
    }

    /**
     * Nur fuer Routen, die ein Konto vor der MFA-Einrichtung braucht (B3: `GET /api/me`). Die Einrichtung selbst
     * laeuft beim Anmeldedienst; die API muss dafuer nichts weiter freigeben.
     */
    public static function handleAllowingMfaEnrollment(Request $request, Response $response): bool
    {
        return self::authenticate($request, $response);
    }

    private static function authenticate(Request $request, Response $response): bool
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
        if ($account->status === AccountStatus::Invited) {
            // Ein gueltiges Token gibt es erst nach Annahme der Einladung: erste Anmeldung => aktiv.
            $account = self::activate($account);
        }
        if (!$account->isActive()) {
            // `reason` (z. B. admin_locked) laesst das Web den Grund nennen (ADR 0002 B2, Nachtrag #12); null bei Konten ohne Grund.
            $response->json(['error' => 'Account is not active', 'code' => 'account_inactive', 'reason' => $account->inactiveReason?->value], 401);
            return false;
        }
        // B2: Tokens aus der Zeit vor der Sitzungsgrenze sind widerrufen, auch wenn sie noch nicht abgelaufen sind.
        if ($account->revokes($token->issuedAt)) {
            $response->json(['error' => 'Session revoked', 'code' => 'session_revoked'], 401);
            return false;
        }
        // Solange ein Widerruf (oder eine TOTP-Zuruecksetzung) beim Anmeldedienst offen ist, leben dessen Refresh-Tokens und
        // koennten neue Tokens erzeugen: jedes Token des Kontos ist abgelehnt. Der erste Request mit einem Token nach der
        // Sitzungsgrenze holt die offenen Aufrufe nach; gelingt das, rueckt die Grenze auf den Nachholzeitpunkt und auch dieses
        // Token ist widerrufen: die Person meldet sich neu an (Nachtrag #16, Tickets #48, #49).
        $openCalls = new OpenCalls();
        if ($openCalls->blocksTokens($account)) {
            $account = $openCalls->releaseTokens($account->publicUserId) ?? $account;
            if (!$account->isActive() || $account->revokes($token->issuedAt) || $openCalls->blocksTokens($account)) {
                $response->json(['error' => 'Session revoked', 'code' => 'session_revoked'], 401);
                return false;
            }
        }

        self::$current = new AuthenticatedSession($account, $token);
        return true;
    }

    private static function activate(Account $account): Account
    {
        $accounts = new AccountRepository();
        $pdo = DB::getInstance();
        $pdo->beginTransaction();
        try {
            if ($accounts->activateIfInvited($account->publicUserId)) {
                (new AuditLog())->record(Actor::account($account->publicUserId), AuditLog::ACCOUNT_ACTIVATED, 'account', $account->publicUserId);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        // Frisch lesen: bei gleichzeitigen Anmeldungen zaehlt der Stand der Datenbank (kein Cache, B1).
        return $accounts->findByPublicUserId($account->publicUserId) ?? $account;
    }

    private static function verifier(): AccessTokenVerifier
    {
        // Ein relativer Pfad gilt ab dem App-Hauptordner, nicht ab dem Arbeitsverzeichnis (unter Apache ist das public/).
        $path = Config::getString('AUTH_PUBLIC_KEY_PATH', '');
        $path = $path !== '' ? Application::resolvePath($path) : '';
        $pem = $path !== '' && is_file($path) ? (string) file_get_contents($path) : '';
        // Fehlende Konfiguration => leere Werte => jedes Token wird abgelehnt (fail-closed).
        return new AccessTokenVerifier($pem, Config::getString('AUTH_TOKEN_ISSUER', ''), Config::getString('AUTH_TOKEN_AUDIENCE', ''));
    }
}
