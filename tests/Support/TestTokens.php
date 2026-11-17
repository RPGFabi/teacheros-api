<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Support;

/**
 * Erzeugt RS256-Testschluessel (Schul-Auth-Instanz und eine fremde Instanz)
 * und signiert damit Access-Tokens. Nichts davon wird eingecheckt: die
 * Schluessel liegen nur im Temp-Verzeichnis des Testlaufs.
 */
final class TestTokens
{
    public const ISSUER = 'https://auth.test-schule.teacheros.test';
    public const AUDIENCE = 'teacheros:test-schule';

    /** @var array<string, \OpenSSLAsymmetricKey> */
    private static array $keys = [];
    private static ?string $publicKeyPath = null;

    /** Pfad zur PEM-Datei mit dem oeffentlichen Schluessel der eigenen Schule. */
    public static function publicKeyPath(): string
    {
        if (self::$publicKeyPath === null) {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'teacheros_test_pub_' . bin2hex(random_bytes(4)) . '.pem';
            file_put_contents($path, self::publicPem('own'));
            register_shutdown_function(static fn () => @unlink($path));
            self::$publicKeyPath = $path;
        }
        return self::$publicKeyPath;
    }

    /** Gueltiges Access-Token; $claims ueberschreibt/ergaenzt, null entfernt einen Claim. */
    public static function access(string $sub, array $claims = [], string $keyName = 'own', array $header = []): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'typ' => 'access',
            'sub' => $sub,
            'iat' => $now,
            'exp' => $now + 900,
            'ip' => '127.0.0.1',
            'usedMFA' => false,
        ], $claims);
        $payload = array_filter($payload, static fn ($v) => $v !== null);
        return self::sign($payload, $keyName, $header);
    }

    public static function sign(array $payload, string $keyName = 'own', array $header = []): string
    {
        $header = array_merge(['alg' => 'RS256', 'typ' => 'JWT'], $header);
        $signingInput = self::b64(json_encode($header)) . '.' . self::b64(json_encode($payload));
        openssl_sign($signingInput, $signature, self::key($keyName), OPENSSL_ALGO_SHA256);
        return $signingInput . '.' . self::b64($signature);
    }

    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function key(string $name): \OpenSSLAsymmetricKey
    {
        return self::$keys[$name] ??= self::generate();
    }

    private static function generate(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('RSA-Testschluessel konnte nicht erzeugt werden: ' . openssl_error_string());
        }
        return $key;
    }

    private static function publicPem(string $name): string
    {
        return openssl_pkey_get_details(self::key($name))['key'];
    }
}
