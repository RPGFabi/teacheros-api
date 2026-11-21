<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Accounts;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\AuthStub;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Sitzungswiderruf durch die Verwaltung (Ticket #48, ADR 0002 B2 und Nachtrag #16): POST /api/accounts/{id}/revoke-sessions,
 * die Sperre aller Tokens, solange der Aufruf an den Anmeldedienst (G3) offen ist, und das Nachholen beim ersten Request
 * mit neuem Token. Ueber HTTP (php-cgi + MariaDB) gegen den Auth-Stub.
 */
final class SessionRevocationTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const ADMIN2 = '22222222-2222-4222-8222-222222222222';
    private const TARGET = '33333333-3333-4333-8333-333333333333';
    private const REVOKER = '44444444-4444-4444-8444-444444444444';

    private HttpTestApp $app;
    private AuthStub $stub;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->stub = AuthStub::start();
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        $this->app->createAccount(self::TARGET, 'active', 'ziel@schule.test');
    }

    protected function tearDown(): void
    {
        $this->stub->stop();
    }

    /** Ein Konto mit `accounts.revoke_sessions` und `accounts.view`, aber ohne Wildcard. */
    private function revoker(string $id = self::REVOKER): void
    {
        $this->app->createAccount($id, 'active');
        $this->app->grantPermission($id, 'accounts.revoke_sessions');
        $this->app->grantPermission($id, 'accounts.view');
    }

    /** @param array<string,mixed> $claims */
    private function as(string $sub, string $method, string $uri, array $claims = []): array
    {
        return $this->app->request($method, $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub, $claims)] + $this->stub->env());
    }

    private function revoke(string $target = self::TARGET, string $actor = self::ADMIN): array
    {
        return $this->as($actor, 'POST', "/api/accounts/$target/revoke-sessions");
    }

    /** Ein Token, das nach der Sitzungsgrenze ausgestellt wurde, wie nach einem neuen Login. */
    private function meWithNewToken(string $sub = self::TARGET): array
    {
        return $this->as($sub, 'GET', '/api/me', ['iat' => time() + 5]);
    }

    /**
     * Ein Token, das nach der Sitzungsgrenze, aber vor "jetzt" ausgestellt wurde: der erste Request damit loest das Nachholen
     * aus, das die Grenze auf "jetzt" setzt (ADR 0002 Nachtrag #16).
     */
    private function meAfterBoundary(string $sub = self::TARGET): array
    {
        $this->app->ageSessionBoundary($sub);
        return $this->as($sub, 'GET', '/api/me', ['iat' => time() - 5]);
    }

    private function boundary(string $id = self::TARGET): ?string
    {
        $stmt = $this->app->pdo()->prepare('SELECT `sessions_valid_after` FROM `accounts` WHERE `public_user_id` = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ?: null;
    }

    private function scriptUnavailable(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->stub->script('revoke-sessions', ['status' => 503, 'body' => []]);
        }
    }

    // ---- Wirkung ----------------------------------------------------------------------------

    public function testDerWiderrufSetztDieSitzungsgrenzeRuftG3MitVertrauenswuerdigenGeraetenAufUndAuditiert(): void
    {
        $r = $this->revoke();

        $this->assertSame(200, $r['status']);
        $this->assertNotNull($this->boundary());
        $calls = $this->stub->revokeSessionsCalls();
        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['signature_valid']);
        $this->assertSame(['event' => 'user.revoke_sessions', 'public_user_ids' => [self::TARGET], 'include_trusted_devices' => true], $calls[0]['body']);
        $audit = $this->app->auditLog();
        $this->assertCount(1, $audit);
        $this->assertSame(['account.sessions_revoked', 'account', self::TARGET, self::ADMIN], [$audit[0]['action'], $audit[0]['object_type'], $audit[0]['object_id'], $audit[0]['actor_account_id']]);
        $this->assertSame(['initiated_by' => 'admin'], json_decode($audit[0]['context'], true));
    }

    public function testDieAntwortIstDieLesesichtOhneOffenenAufrufNachErfolg(): void
    {
        $r = $this->revoke();

        $this->assertSame('active', $r['json']['account']['status']);
        $this->assertSame([], $r['json']['account']['open_calls']);
        $this->assertSame([], $this->app->openCalls(self::TARGET));
    }

    public function testEinAltesTokenLiefertNachDemWiderrufSofort401SessionRevokedEinNeuesLaeuftDurch(): void
    {
        $old = TestTokens::access(self::TARGET, ['iat' => time() - 30]);
        $me = fn (string $token) => $this->app->request('GET', '/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] + $this->stub->env());
        $this->assertSame(200, $me($old)['status']);

        $this->revoke();

        $r = $me($old);
        $this->assertSame([401, 'session_revoked'], [$r['status'], $r['json']['code']]);
        $this->assertSame(200, $this->meWithNewToken()['status'], 'ein neuer Login gelingt');
    }

    public function testDasKontoBleibtAktivMitRollenUndRechten(): void
    {
        $this->app->assignRole(self::TARGET, 'lehrkraft');

        $this->revoke();

        $row = $this->app->accounts();
        $target = array_values(array_filter($row, static fn ($a) => $a['public_user_id'] === self::TARGET))[0];
        $this->assertSame('active', $target['status']);
        $this->assertNull($target['inactive_reason']);
    }

    // ---- Anmeldedienst nicht erreichbar oder lehnt ab ---------------------------------------------

    public function testIstDerAnmeldedienstNichtErreichbarBleibenGrenzeUndOffenerAufrufBestehen(): void
    {
        $this->scriptUnavailable();

        $r = $this->revoke();

        $this->assertSame(200, $r['status']);
        $this->assertNotNull($this->boundary());
        $this->assertSame([['operation' => 'revoke_sessions', 'rejection_code' => null]], $r['json']['account']['open_calls']);
        $this->assertSame(['revoke_sessions' => null], $this->app->openCalls(self::TARGET));
        $this->assertCount(1, $this->app->auditLog());
    }

    public function testEineFachlicheAblehnungBleibtMitIhremCodeOffen(): void
    {
        $this->stub->script('revoke-sessions', ['status' => 400, 'body' => ['error' => 'x', 'code' => 'invalid_request_body']]);

        $r = $this->revoke();

        $this->assertSame(200, $r['status']);
        $this->assertSame([['operation' => 'revoke_sessions', 'rejection_code' => 'invalid_request_body']], $r['json']['account']['open_calls']);
    }

    // ---- Sperre aller Tokens, solange der Aufruf offen ist ------------------------------------------

    public function testSolangeDerWiderrufOffenIstLehntDasBackendAuchTokensAusNeuenAnmeldungenAb(): void
    {
        $this->scriptUnavailable(2);
        $this->revoke();

        $r = $this->meWithNewToken();

        $this->assertSame([401, 'session_revoked'], [$r['status'], $r['json']['code']]);
        $this->assertSame(['revoke_sessions' => null], $this->app->openCalls(self::TARGET));
    }

    public function testDieSperreGiltAuchFuerFachlicheRoutenNichtFuerAndereKonten(): void
    {
        $this->scriptUnavailable(2);
        $this->app->grantPermission(self::TARGET, 'accounts.view');
        $this->revoke();

        $target = $this->as(self::TARGET, 'GET', '/api/accounts', ['iat' => time() + 5]);
        $admin = $this->as(self::ADMIN, 'GET', '/api/accounts');

        $this->assertSame([401, 'session_revoked'], [$target['status'], $target['json']['code']]);
        $this->assertSame(200, $admin['status']);
    }

    public function testEinAltesTokenWirdOhneNachholversuchAbgelehnt(): void
    {
        $this->scriptUnavailable();
        $this->revoke();

        $r = $this->as(self::TARGET, 'GET', '/api/me', ['iat' => time() - 60]);

        $this->assertSame([401, 'session_revoked'], [$r['status'], $r['json']['code']]);
        $this->assertCount(1, $this->stub->revokeSessionsCalls(), 'nur der erste Versuch der Verwaltung, keiner fuer ein altes Token');
    }

    public function testDerErsteRequestMitTokenNachDerGrenzeHoltDenWiderrufNachUndLehntDasTokenAbDerNeueLoginGelingt(): void
    {
        $this->scriptUnavailable();
        $this->revoke();

        $first = $this->meAfterBoundary();
        $second = $this->meWithNewToken();

        $this->assertSame([401, 'session_revoked'], [$first['status'], $first['json']['code']], 'die Grenze rueckt auf den Nachholzeitpunkt: auch das Token, das den Versuch ausloest, ist widerrufen');
        $this->assertSame([], $this->app->openCalls(self::TARGET), 'der Widerruf ist nachgeholt');
        $this->assertSame(200, $second['status'], 'ein neuer Login nach dem Nachholen gelingt');
        $this->assertCount(2, $this->stub->revokeSessionsCalls(), 'ein Nachholversuch, danach ist nichts mehr offen');
        $this->assertCount(1, $this->app->auditLog(), 'das Nachholen schreibt keinen Audit-Eintrag');
    }

    public function testDasTokenAusDerZeitVorDemNachholenBleibtAuchNachDemNachholenWiderrufen(): void
    {
        $this->scriptUnavailable();
        $this->revoke();
        $triggering = $this->meAfterBoundary();
        $this->assertSame(401, $triggering['status']);

        $again = $this->as(self::TARGET, 'GET', '/api/me', ['iat' => time() - 5]);

        $this->assertSame([401, 'session_revoked'], [$again['status'], $again['json']['code']]);
        $this->assertCount(2, $this->stub->revokeSessionsCalls(), 'das zweite Mal ohne Nachholversuch: es ist nichts mehr offen');
    }

    public function testNachDemNachholenBleibenAlteTokensWiderrufen(): void
    {
        $this->scriptUnavailable();
        $this->revoke();
        $this->meAfterBoundary();

        $old = $this->as(self::TARGET, 'GET', '/api/me', ['iat' => time() - 60]);

        $this->assertSame([401, 'session_revoked'], [$old['status'], $old['json']['code']]);
    }

    public function testLehntDerAnmeldedienstDasNachholenAbBleibtDieSperreUndDerCodeStehtAmAufruf(): void
    {
        $this->scriptUnavailable();
        $this->revoke();
        $this->stub->script('revoke-sessions', ['status' => 400, 'body' => ['error' => 'x', 'code' => 'invalid_request_body']]);

        $r = $this->meWithNewToken();

        $this->assertSame([401, 'session_revoked'], [$r['status'], $r['json']['code']]);
        $this->assertSame(['revoke_sessions' => 'invalid_request_body'], $this->app->openCalls(self::TARGET));
    }

    public function testEinVonAnfangAnAbgelehnterWiderrufSperrtJedesTokenBisZumNachholen(): void
    {
        $this->stub->script('revoke-sessions', ['status' => 400, 'body' => ['error' => 'x', 'code' => 'invalid_request_body']]);
        $this->revoke();

        $this->assertSame(401, $this->meAfterBoundary()['status'], 'das Nachholen gelingt, das Token ist dennoch widerrufen');
        $this->assertSame([], $this->app->openCalls(self::TARGET));
        $this->assertSame(200, $this->meWithNewToken()['status'], 'der neue Login gelingt');
    }

    // ---- Abgleich wiederholen und Bausteine fuer #49 und #50 -------------------------------------

    public function testAbgleichWiederholenHoltDenWiderrufNachAuchMitNurDemWiderrufsrecht(): void
    {
        $this->revoker();
        $this->scriptUnavailable();
        $this->revoke();

        $r = $this->as(self::REVOKER, 'POST', '/api/accounts/' . self::TARGET . '/resync-login');

        $this->assertSame(200, $r['status']);
        $this->assertSame([], $r['json']['account']['open_calls']);
        $this->assertCount(2, $this->stub->revokeSessionsCalls());
    }

    public function testDieLesesichtBietetDenAbgleichSolangeDerWiderrufOffenIst(): void
    {
        $this->scriptUnavailable();
        $r = $this->revoke();

        $this->assertContains('resync_login', array_column($r['json']['account_actions'], 'action'));
    }

    public function testDerBausteinVersuchtG3SooftWieVerlangtUndMeldetDenAusgang(): void
    {
        $this->scriptUnavailable(3);

        $out = $this->app->cli('tests/Support/revoke-sessions.php', [self::TARGET, '3', 'self'], $this->stub->env());

        $this->assertSame(0, $out['code'], $out['err']);
        $this->assertSame(['completed' => false], json_decode($out['out'], true));
        $this->assertCount(3, $this->stub->revokeSessionsCalls());
        $this->assertSame(['revoke_sessions' => null], $this->app->openCalls(self::TARGET));
        $this->assertSame(['initiated_by' => 'self'], json_decode($this->app->auditLog()[0]['context'], true));
    }

    public function testDerBausteinHoertNachDemErstenErfolgAuf(): void
    {
        $this->scriptUnavailable();

        $out = $this->app->cli('tests/Support/revoke-sessions.php', [self::TARGET, '3', 'self'], $this->stub->env());

        $this->assertSame(['completed' => true], json_decode($out['out'], true));
        $this->assertCount(2, $this->stub->revokeSessionsCalls());
        $this->assertSame([], $this->app->openCalls(self::TARGET));
    }

    // ---- Rechte und Regeln ------------------------------------------------------------------

    public function testMitRechtUndAccountsViewDarfManWiderrufen(): void
    {
        $this->revoker();

        $r = $this->revoke(self::TARGET, self::REVOKER);

        $this->assertSame(200, $r['status']);
        $this->assertNotNull($this->boundary());
    }

    public function testOhneDasRechtIst403ForbiddenUndNichtsAendertSich(): void
    {
        $this->app->createAccount(self::REVOKER, 'active');
        $this->app->grantPermission(self::REVOKER, 'accounts.view');

        $r = $this->revoke(self::TARGET, self::REVOKER);

        $this->assertSame([403, 'forbidden'], [$r['status'], $r['json']['code']]);
        $this->assertNull($this->boundary());
        $this->assertSame([], $this->stub->revokeSessionsCalls());
        $this->assertSame([], $this->app->auditLog());
    }

    public function testOhneAccountsViewIst403(): void
    {
        $this->app->createAccount(self::REVOKER, 'active');
        $this->app->grantPermission(self::REVOKER, 'accounts.revoke_sessions');

        $r = $this->revoke(self::TARGET, self::REVOKER);

        $this->assertSame(403, $r['status']);
        $this->assertNull($this->boundary());
    }

    public function testNurFuerKontoseDessenRechteManAlleSelbstHatP2(): void
    {
        $this->revoker();
        $this->app->assignRole(self::TARGET, 'schuladmin');

        $r = $this->revoke(self::TARGET, self::REVOKER);

        $this->assertSame([403, 'permission_not_held'], [$r['status'], $r['json']['code']]);
        $this->assertNull($this->boundary());
        $this->assertSame([], $this->stub->revokeSessionsCalls());
    }

    public function testEinSchuladminDarfEinenAnderenSchuladminWiderrufen(): void
    {
        $this->app->createAccount(self::ADMIN2, 'active');
        $this->app->assignRole(self::ADMIN2, 'schuladmin');

        $r = $this->revoke(self::ADMIN2);

        $this->assertSame(200, $r['status']);
        $this->assertNotNull($this->boundary(self::ADMIN2));
    }

    public function testDasEigeneKontoIst409SelfRevocation(): void
    {
        $r = $this->revoke(self::ADMIN, self::ADMIN);

        $this->assertSame([409, 'self_revocation'], [$r['status'], $r['json']['code']]);
        $this->assertNull($this->boundary(self::ADMIN));
        $this->assertSame([], $this->stub->revokeSessionsCalls());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nichtAktiveStatus')]
    public function testNichtAktiveKontenSind409AccountNotActive(string $status): void
    {
        $this->app->setAccountStatus(self::TARGET, $status);

        $r = $this->revoke();

        $this->assertSame([409, 'account_not_active'], [$r['status'], $r['json']['code']]);
        $this->assertNull($this->boundary());
        $this->assertSame([], $this->stub->revokeSessionsCalls());
        $this->assertSame([], $this->app->auditLog());
    }

    /** @return array<string,array{string}> */
    public static function nichtAktiveStatus(): array
    {
        return ['eingeladen' => ['invited'], 'deaktiviert' => ['inactive']];
    }

    public function testEinUnbekanntesKontoIst404(): void
    {
        $r = $this->revoke('99999999-9999-4999-8999-999999999999');

        $this->assertSame([404, 'account_not_found'], [$r['status'], $r['json']['code']]);
    }

    // ---- Lesesicht ------------------------------------------------------------------------------

    public function testDieLesesichtNenntDieAktionMitErlaubnisOderBegruendung(): void
    {
        $this->revoker();
        $this->app->assignRole(self::TARGET, 'schuladmin');

        $admin = $this->as(self::ADMIN, 'GET', '/api/accounts/' . self::TARGET . '/access');
        $limited = $this->as(self::REVOKER, 'GET', '/api/accounts/' . self::TARGET . '/access');
        $self = $this->as(self::ADMIN, 'GET', '/api/accounts/' . self::ADMIN . '/access');

        $find = static fn (array $r) => array_values(array_filter($r['json']['account_actions'], static fn ($a) => $a['action'] === 'revoke_sessions'))[0] ?? null;
        $this->assertSame(['action' => 'revoke_sessions', 'allowed' => true, 'code' => null, 'reason' => null], $find($admin));
        $this->assertSame(['revoke_sessions', false, 'permission_not_held'], [$find($limited)['action'], $find($limited)['allowed'], $find($limited)['code']]);
        $this->assertSame([false, 'self_revocation'], [$find($self)['allowed'], $find($self)['code']]);
    }

    public function testWerNurWiderrufenDarfSiehtDasKontodetail(): void
    {
        $this->revoker();

        $r = $this->as(self::REVOKER, 'GET', '/api/accounts/' . self::TARGET . '/access');

        $this->assertSame(200, $r['status']);
    }
}
