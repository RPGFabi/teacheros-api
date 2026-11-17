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
    /**
     * Laedt ein Konto ein (G5, idempotent). Ein Anmeldename wird als `username` mitgesendet; die Antwort
     * kann ab G6 `username` und bei Einladung ohne E-Mail einmalig `invite_code` enthalten.
     *
     * @throws AuthServiceUnavailableException bei Ausfall, Timeout, 5xx oder unerwarteter Antwort
     * @throws CodedException `username_taken` (409), wenn der Anmeldedienst den Namen schon vergeben hat,
     *                        `invalid_username` (400), wenn er ihn nicht annimmt
     */
    public function invite(?string $email, ?string $username): InviteResult
    {
        $payload = ['event' => 'user.invite'];
        if ($email !== null) {
            $payload['email'] = $email;
        }
        if ($username !== null) {
            $payload['username'] = $username;
        }

        [$status, $json] = $this->post('/internal/users/invite', $payload);

        if ($status === 409 && ($json['code'] ?? null) === 'username_taken') {
            throw new CodedException('username_taken', 409, 'Der Anmeldename ist schon vergeben.');
        }
        if ($status === 400 && ($json['code'] ?? null) === 'invalid_username') {
            throw new CodedException('invalid_username', 400, 'Der Anmeldename ist ungueltig.');
        }
        $id = $json['public_user_id'] ?? null;
        if ($status !== 200 || !is_string($id) || $id === '') {
            throw new AuthServiceUnavailableException("unerwartete Antwort (HTTP $status) auf die Einladung");
        }

        return new InviteResult(
            $id,
            ($json['created'] ?? false) === true,
            ($json['already_active'] ?? false) === true,
            is_string($json['username'] ?? null) ? $json['username'] : null,
            is_string($json['invite_code'] ?? null) ? $json['invite_code'] : null,
        );
    }

    /**
     * Loescht ein Konto im Anmeldedienst (idempotent), z. B. eine ersetzte, nie angenommene Einladung.
     *
     * @throws AuthServiceUnavailableException
     */
    public function deleteUser(string $publicUserId): void
    {
        [$status] = $this->post('/internal/users/delete', ['event' => 'user.delete', 'public_user_id' => $publicUserId]);
        if ($status !== 200) {
            throw new AuthServiceUnavailableException("unerwartete Antwort (HTTP $status) auf das Loeschen");
        }
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
