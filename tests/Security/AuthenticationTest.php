<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Integrationstests gegen php-cgi + MariaDB: GET /api/me ist die erste
 * geschuetzte Route; sie belegt die gesamte Authentifizierungskette (ADR 0002 B1).
 */
final class AuthenticationTest extends TestCase
{
    private const SUB = '11111111-1111-4111-8111-111111111111';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
    }

    private function me(?string $token): array
    {
        $env = $token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        return $this->app->request('GET', '/api/me', $env);
    }

    public function testMeOhneTokenLiefert401(): void
    {
        $r = $this->me(null);

        $this->assertSame(401, $r['status']);
    }

    public function testMeLiefertDatenDesAngemeldetenKontos(): void
    {
        $this->app->createAccount(self::SUB, 'active');

        $r = $this->me(TestTokens::access(self::SUB, ['usedMFA' => true]));

        $this->assertSame(200, $r['status']);
        $this->assertSame(self::SUB, $r['json']['account']['id']);
        $this->assertSame('active', $r['json']['account']['status']);
        $this->assertTrue($r['json']['session']['used_mfa']);
    }

    /** @return array<string, array{callable():string}> */
    public static function ungueltigeTokens(): array
    {
        $sub = self::SUB;
        return [
            'falsche Signatur (anderer Schluessel)' => [static fn () => TestTokens::access($sub, [], 'other-school')],
            'falsches iss' => [static fn () => TestTokens::access($sub, ['iss' => 'https://auth.andere-schule.test'])],
            'falsches aud' => [static fn () => TestTokens::access($sub, ['aud' => 'teacheros:andere-schule'])],
            'abgelaufen' => [static fn () => TestTokens::access($sub, ['iat' => time() - 2000, 'exp' => time() - 1000])],
            'typ mfa_pending' => [static fn () => TestTokens::access($sub, ['typ' => 'mfa_pending'])],
            'typ fehlt' => [static fn () => TestTokens::access($sub, ['typ' => null])],
            'alg none' => [static fn () => TestTokens::b64('{"alg":"none","typ":"JWT"}') . '.' . TestTokens::b64(json_encode(['iss' => TestTokens::ISSUER, 'aud' => TestTokens::AUDIENCE, 'typ' => 'access', 'sub' => $sub, 'exp' => time() + 900])) . '.'],
            'alg HS256' => [static fn () => TestTokens::access($sub, [], 'own', ['alg' => 'HS256'])],
            'kein JWT' => [static fn () => 'kein-jwt'],
        ];
    }

    #[DataProvider('ungueltigeTokens')]
    public function testUngueltigesTokenLiefert401(callable $makeToken): void
    {
        $this->app->createAccount(self::SUB, 'active');

        $r = $this->me($makeToken());

        $this->assertSame(401, $r['status']);
    }

    public function testTokenEinerAnderenSchulinstanzLiefert401(): void
    {
        // Source of Truth 17.14 Nr. 1: gleiche sub, gleiche Claims, aber Schluessel einer anderen Schule.
        $this->app->createAccount(self::SUB, 'active');

        $r = $this->me(TestTokens::access(self::SUB, [], 'other-school'));

        $this->assertSame(401, $r['status']);
    }

    public function testUnbekanntesSubLiefert401(): void
    {
        $r = $this->me(TestTokens::access('99999999-9999-4999-8999-999999999999'));

        $this->assertSame(401, $r['status']);
    }

    #[DataProvider('nichtAktiveStatus')]
    public function testKontoOhneStatusAktivLiefert401AccountInactive(string $status): void
    {
        $this->app->createAccount(self::SUB, $status);

        $r = $this->me(TestTokens::access(self::SUB));

        $this->assertSame(401, $r['status']);
        $this->assertSame('account_inactive', $r['json']['code']);
    }

    /** @return array<string, array{string}> */
    public static function nichtAktiveStatus(): array
    {
        return ['inactive' => ['inactive'], 'invited' => ['invited']];
    }

    public function testDeaktiviertesKontoWirdBeimNaechstenRequestAbgewiesen(): void
    {
        // ADR 0002 B1 Pflichttest: kein Cache ueber den Request hinaus.
        $this->app->createAccount(self::SUB, 'active');
        $token = TestTokens::access(self::SUB);
        $this->assertSame(200, $this->me($token)['status']);

        $this->app->setAccountStatus(self::SUB, 'inactive');
        $r = $this->me($token);

        $this->assertSame(401, $r['status']);
        $this->assertSame('account_inactive', $r['json']['code']);
    }
}
