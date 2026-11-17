<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

/**
 * Prueft ein Access-Token der Auth-Instanz der eigenen Schule lokal:
 * RS256, typ=access, iss, aud, exp. Fail-closed: jede Abweichung wirft.
 */
final class AccessTokenVerifier
{
    public function __construct(
        private readonly string $publicKeyPem,
        private readonly string $issuer,
        private readonly string $audience,
    ) {
    }

    /** @throws InvalidTokenException */
    public function verify(string $jwt, ?int $now = null): AccessToken
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new InvalidTokenException('Kein JWT');
        }
        [$h64, $p64, $s64] = $parts;
        $header = self::decodeJson($h64);
        $payload = self::decodeJson($p64);
        $signature = self::b64decode($s64);

        // alg vor der Signaturpruefung festnageln (Algorithm-Confusion, "none")
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new InvalidTokenException('Unerwarteter Algorithmus');
        }
        $key = openssl_pkey_get_public($this->publicKeyPem);
        if ($key === false || $signature === '' || openssl_verify($h64 . '.' . $p64, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidTokenException('Ungueltige Signatur');
        }

        foreach (['iss' => $this->issuer, 'aud' => $this->audience, 'typ' => 'access'] as $claim => $expected) {
            $actual = $payload[$claim] ?? null;
            if ($expected === '' || !is_string($actual) || !hash_equals($expected, $actual)) {
                throw new InvalidTokenException("Unerwarteter Claim $claim");
            }
        }
        $exp = $payload['exp'] ?? null;
        if (!is_int($exp) || ($now ?? time()) >= $exp) {
            throw new InvalidTokenException('Token abgelaufen');
        }
        $sub = $payload['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw new InvalidTokenException('sub fehlt');
        }
        $iat = $payload['iat'] ?? null;

        return new AccessToken($sub, is_int($iat) ? $iat : null, $exp, ($payload['usedMFA'] ?? false) === true);
    }

    private static function decodeJson(string $b64): array
    {
        $data = json_decode(self::b64decode($b64), true);
        if (!is_array($data)) {
            throw new InvalidTokenException('Ungueltiges JWT-Segment');
        }
        return $data;
    }

    private static function b64decode(string $b64): string
    {
        $decoded = base64_decode(strtr($b64, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
