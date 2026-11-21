<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Accounts;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\AuthStub;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Eigener Sitzungswiderruf auf "Mein Konto" (Ticket #50, ADR 0002 B2 und Nachtrag #16): POST /api/me/revoke-sessions.
 * Kein besonderes Recht; das Backend versucht G3 sofort bis zu dreimal und antwortet sonst 502 `auth_unavailable`.
 * Ueber HTTP (php-cgi + MariaDB) gegen den Auth-Stub.
 */
final class OwnSessionRevocationTest extends TestCase
{
    private const ACCOUNT = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';

    private HttpTestApp $app;
    private AuthStub $stub;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->stub = AuthStub::start();
        $this->app->createAccount(self::ACCOUNT, 'active');
        $this->app->createAccount(self::OTHER, 'active');
    }

    protected function tearDown(): void
    {
        $this->stub->stop();
    }

    /** @param array<string,mixed> $claims */
    private function as(string $sub, string $method, string $uri, array $claims = []): array
    {
        return $this->app->request($method, $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub, $claims)] + $this->stub->env());
    }

    private function revokeOwn(string $sub = self::ACCOUNT, array $claims = []): array
    {
        return $this->as($sub, 'POST', '/api/me/revoke-sessions', $claims);
    }

    private function boundary(string $id = self::ACCOUNT): ?string
    {
        $stmt = $this->app->pdo()->prepare('SELECT `sessions_valid_after` FROM `accounts` WHERE `public_user_id` = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ?: null;
    }

    private function scriptUnavailable(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->stub->script('revoke-sessions', ['status' => 503, 'body' => []]);
        }
    }

    public function testEinKontoOhneBesonderesRechtBeendetSeineSitzungenUndRuftG3EinmalAuf(): void
    {
        $r = $this->revokeOwn();

        $this->assertSame(200, $r['status']);
        $this->assertNotNull($this->boundary());
        $calls = $this->stub->revokeSessionsCalls();
        $this->assertCount(1, $calls);
        $this->assertSame(['event' => 'user.revoke_sessions', 'public_user_ids' => [self::ACCOUNT], 'include_trusted_devices' => true], $calls[0]['body']);
        $this->assertSame([], $this->app->openCalls(self::ACCOUNT));
    }

    public function testDasBisherigeTokenLiefert401SessionRevokedEinNeuerLoginGelingt(): void
    {
        $old = ['iat' => time() - 30];
        $this->assertSame(200, $this->as(self::ACCOUNT, 'GET', '/api/me', $old)['status']);

        $this->revokeOwn(claims: $old);

        $r = $this->as(self::ACCOUNT, 'GET', '/api/me', $old);
        $this->assertSame([401, 'session_revoked'], [$r['status'], $r['json']['code']]);
        $this->assertSame(200, $this->as(self::ACCOUNT, 'GET', '/api/me', ['iat' => time() + 5])['status']);
    }

    public function testDerAuditEintragNenntDasKontoAlsAkteurMitInitiatedBySelf(): void
    {
        $this->revokeOwn();

        $audit = $this->app->auditLog();
        $this->assertCount(1, $audit);
        $this->assertSame(['account.sessions_revoked', 'account', self::ACCOUNT, self::ACCOUNT], [$audit[0]['action'], $audit[0]['object_type'], $audit[0]['object_id'], $audit[0]['actor_account_id']]);
        $this->assertSame(['initiated_by' => 'self'], json_decode($audit[0]['context'], true));
    }

    public function testEinEinzelnerFehlversuchWirdWiederholtUndDerWiderrufGelingt(): void
    {
        $this->scriptUnavailable(2);

        $r = $this->revokeOwn();

        $this->assertSame(200, $r['status']);
        $this->assertCount(3, $this->stub->revokeSessionsCalls());
        $this->assertSame([], $this->app->openCalls(self::ACCOUNT));
    }

    public function testNachDreiFehlversuchenIst502AuthUnavailableMitHinweisUndDerWiderrufBleibtOffen(): void
    {
        $this->scriptUnavailable(3);

        $r = $this->revokeOwn();

        $this->assertSame([502, 'auth_unavailable'], [$r['status'], $r['json']['code']]);
        $this->assertStringContainsString('später erneut an', $r['json']['error']);
        $this->assertStringContainsString('Schule', $r['json']['error']);
        $this->assertCount(3, $this->stub->revokeSessionsCalls());
        $this->assertNotNull($this->boundary());
        $this->assertSame(['revoke_sessions' => null], $this->app->openCalls(self::ACCOUNT));
        $this->assertCount(1, $this->app->auditLog());
    }

    public function testEineFachlicheAblehnungWirdOhneWiederholungMitStatusUndCodeDurchgereicht(): void
    {
        $this->stub->script('revoke-sessions', ['status' => 400, 'body' => ['error' => 'x', 'code' => 'invalid_request_body']]);

        $r = $this->revokeOwn();

        $this->assertSame([400, 'invalid_request_body'], [$r['status'], $r['json']['code']], 'kein 502: der Dienst ist erreichbar und hat abgelehnt');
        $this->assertCount(1, $this->stub->revokeSessionsCalls(), 'ein Wiederholen aendert eine fachliche Ablehnung nicht');
        $this->assertSame(['revoke_sessions' => 'invalid_request_body'], $this->app->openCalls(self::ACCOUNT));
        $this->assertNotNull($this->boundary(), 'der Widerruf bleibt lokal bestehen');
    }

    public function testBeiOffenemWiderrufHeiltDerErsteRequestMitTokenNachDerGrenzeSichUndLehntDasTokenAb(): void
    {
        $this->scriptUnavailable(3);
        $this->revokeOwn();
        $this->app->ageSessionBoundary(self::ACCOUNT);

        $first = $this->as(self::ACCOUNT, 'GET', '/api/me', ['iat' => time() - 5]);
        $login = $this->as(self::ACCOUNT, 'GET', '/api/me', ['iat' => time() + 5]);

        $this->assertSame([401, 'session_revoked'], [$first['status'], $first['json']['code']]);
        $this->assertSame([], $this->app->openCalls(self::ACCOUNT));
        $this->assertSame(200, $login['status'], 'die erneute Anmeldung gelingt');
        $this->assertCount(1, $this->app->auditLog(), 'das Nachholen schreibt keinen Audit-Eintrag');
    }

    public function testNurDasEigeneKontoWirdBeendet(): void
    {
        $this->revokeOwn();

        $this->assertNull($this->boundary(self::OTHER));
        $this->assertSame(200, $this->as(self::OTHER, 'GET', '/api/me')['status']);
    }

    public function testOhneTokenIst401(): void
    {
        $r = $this->app->request('POST', '/api/me/revoke-sessions', $this->stub->env());

        $this->assertSame(401, $r['status']);
        $this->assertSame([], $this->stub->revokeSessionsCalls());
    }

    public function testEinKontoMitMfaPflichtOhneZweitenFaktorDarfNichtWiderrufenWieAlleFachlichenRouten(): void
    {
        $this->app->pdo()->prepare('UPDATE `accounts` SET `mfa_required` = 1 WHERE `public_user_id` = ?')->execute([self::ACCOUNT]);

        $r = $this->revokeOwn(claims: ['usedMFA' => false]);

        $this->assertSame([403, 'mfa_enrollment_required'], [$r['status'], $r['json']['code']]);
        $this->assertNull($this->boundary());
    }
}
