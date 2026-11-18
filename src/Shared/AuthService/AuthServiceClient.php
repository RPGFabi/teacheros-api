<?php

declare(strict_types=1);

namespace TeacherOS\Shared\AuthService;

use Hoelzle\Core\Config\Config;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Signierte interne Aufrufe an den Anmeldedienst der eigenen Schule (ADR 0002, Teil A).
 * Signatur wie `RequestSignature` aus php-internal-notifications: HMAC-SHA256 ueber
 * "<timestamp>.<nonce>.<roher Body>", Header X-Internal-Timestamp, X-Internal-Nonce und
 * die Signatur ("sha256=<hex>") im Header AUTH_SERVICE_SIGNATURE_HEADER (Standard X-Webhook-Signature).
 *
 * Konfiguration: AUTH_SERVICE_URL, AUTH_SERVICE_WEBHOOK_SECRET (= AUTH_SERVER_WEBHOOK_SECRET der
 * Auth-Instanz), AUTH_SERVICE_TIMEOUT_SECONDS (Standard 5).
 */
final class AuthServiceClient
{
    /** Deutsche Texte fuer die fachlichen Codes, die der Anmeldedienst kennt (andere Codes bekommen einen allgemeinen Text). */
    private const REJECTIONS = [
        'username_taken' => 'Der Anmeldename ist schon vergeben.',
        'invalid_username' => 'Der Anmeldename ist ungueltig.',
        'username_login_disabled' => 'Der Anmeldedienst erlaubt keine Konten ohne E-Mail-Adresse (Anmeldename-Anmeldung ist ausgeschaltet).',
        'account_disabled' => 'Das Konto ist im Anmeldedienst gesperrt und kann nicht eingeladen werden.',
    ];

    /**
     * Laedt ein Konto ein (G5, idempotent). Ein Anmeldename wird als `username` mitgesendet; die Antwort
     * kann ab G6 `username`, `expires_at` und bei Einladung ohne E-Mail einmalig `invite_code` enthalten.
     *
     * @throws AuthServiceUnavailableException bei Ausfall, Timeout, 5xx oder unlesbarer Antwort
     * @throws CodedException fachliche Ablehnung des Anmeldedienstes mit dessen Status und Code, z. B.
     *                        `username_taken` (409), `invalid_username` (400), `username_login_disabled` (400),
     *                        `account_disabled` (409); 400 ohne Code ist `invalid_request`
     */
    public function invite(?string $email, ?string $username): InviteAnswer
    {
        $payload = ['event' => 'user.invite'];
        if ($email !== null) {
            $payload['email'] = $email;
        }
        if ($username !== null) {
            $payload['username'] = $username;
        }

        [$status, $json] = $this->post('/internal/users/invite', $payload);

        $id = $json['public_user_id'] ?? null;
        if ($status !== 200 || !is_string($id) || $id === '') {
            throw $this->failure($status, $json, 'die Einladung');
        }

        return new InviteAnswer(
            $id,
            ($json['created'] ?? false) === true,
            ($json['already_active'] ?? false) === true,
            is_string($json['username'] ?? null) ? $json['username'] : null,
            is_string($json['invite_code'] ?? null) ? $json['invite_code'] : null,
            is_string($json['expires_at'] ?? null) ? $json['expires_at'] : null,
        );
    }

    /**
     * Loescht ein Konto im Anmeldedienst (idempotent), z. B. eine ersetzte, nie angenommene Einladung.
     *
     * @throws AuthServiceUnavailableException
     * @throws CodedException wie bei {@see invite()}
     */
    public function deleteUser(string $publicUserId): void
    {
        [$status, $json] = $this->post('/internal/users/delete', ['event' => 'user.delete', 'public_user_id' => $publicUserId]);
        if ($status !== 200) {
            throw $this->failure($status, $json, 'das Loeschen');
        }
    }

    /**
     * Sperrt den Login eines Kontos im Anmeldedienst (G4a, idempotent) und widerruft dessen Refresh-Tokens.
     * `$reason` ist ein Sperrgrund des Dienstes (`admin_locked`, `left_school`, `security_lock`); das Ereignis heisst
     * unveraendert `user.self_deleted` (der Grund steht in `reason`).
     *
     * @throws AuthServiceUnavailableException
     * @throws CodedException wie bei {@see invite()}, z. B. `user_not_found` (404)
     */
    public function disableLogin(string $publicUserId, string $reason): void
    {
        [$status, $json] = $this->post('/internal/users/disable-login', ['event' => 'user.self_deleted', 'public_user_id' => $publicUserId, 'reason' => $reason]);
        if ($status !== 200 || ($json['ok'] ?? false) !== true) { // 200 ohne lesbares {"ok": true} gilt nicht als Erfolg
            throw $this->failure($status, $json, 'das Sperren des Logins');
        }
    }

    /**
     * Gibt den Login eines Kontos im Anmeldedienst wieder frei (G4b, idempotent). Sitzungen kommen dadurch nicht zurueck.
     *
     * @throws AuthServiceUnavailableException
     * @throws CodedException wie bei {@see invite()}: fachliche Ablehnungen mit Status und Code, z. B. `self_deleted` (409),
     *                        `email_unconfirmed` (409), `user_not_found` (404)
     */
    public function enableLogin(string $publicUserId): void
    {
        [$status, $json] = $this->post('/internal/users/enable-login', ['event' => 'user.enable_login', 'public_user_id' => $publicUserId]);
        if ($status !== 200 || ($json['ok'] ?? false) !== true) { // 200 ohne lesbares {"ok": true} gilt nicht als Erfolg
            throw $this->failure($status, $json, 'das Freigeben des Logins');
        }
    }

    /**
     * Uebersetzt eine nicht erfolgreiche Antwort: fachliche 4xx (mit `code`, bei 400 mit lesbarer Antwort auch ohne: `invalid_request`)
     * gehen mit ihrem Status und Code an den Aufrufer; alles andere (5xx, Fehlkonfiguration wie ein falsches
     * Secret, unlesbare Antwort) ist "Anmeldedienst nicht verfuegbar".
     *
     * @param array<string,mixed> $json
     */
    private function failure(int $status, array $json, string $what): CodedException
    {
        if ($status >= 400 && $status < 500) {
            $code = $json['code'] ?? null;
            if (is_string($code) && preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code) === 1) {
                return new CodedException($code, $status, self::REJECTIONS[$code] ?? "Der Anmeldedienst hat die Anfrage abgelehnt ($code).");
            }
            if ($status === 400 && $json !== [] && !isset($json['code'])) {
                return new CodedException('invalid_request', 400, 'Der Anmeldedienst hat die Anfrage als ungueltig abgelehnt.');
            }
        }

        return new AuthServiceUnavailableException("unerwartete Antwort (HTTP $status) auf $what");
    }

    /** @return array{int, array<string,mixed>} */
    private function post(string $path, array $payload): array
    {
        $base = rtrim(Config::getString('AUTH_SERVICE_URL', ''), '/');
        $secret = Config::getString('AUTH_SERVICE_WEBHOOK_SECRET', '');
        if ($base === '' || $secret === '') {
            throw new AuthServiceUnavailableException('AUTH_SERVICE_URL oder AUTH_SERVICE_WEBHOOK_SECRET fehlt');
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signatureHeader = Config::getString('AUTH_SERVICE_SIGNATURE_HEADER', 'X-Webhook-Signature');
        $headers = [
            'Content-Type: application/json',
            'X-Internal-Timestamp: ' . $timestamp,
            'X-Internal-Nonce: ' . $nonce,
            $signatureHeader . ': sha256=' . hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $secret),
        ];

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => (float) Config::getString('AUTH_SERVICE_TIMEOUT_SECONDS', '5'),
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($base . $path, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if ($response === false || $status === 0) {
            throw new AuthServiceUnavailableException('keine Antwort (Verbindung oder Zeitueberschreitung)');
        }

        $json = json_decode($response, true);
        return [$status, is_array($json) ? $json : []];
    }
}
