<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Accounts;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\AuthStub;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * TOTP-Zuruecksetzung durch die Verwaltung (Ticket #49, ADR 0002 B2 und Nachtrag #16): POST /api/accounts/{id}/reset-totp,
 * der eingeschlossene Sitzungswiderruf, die Sperre aller Tokens, solange der Aufruf an den Anmeldedienst (G1) offen ist,
 * und das Nachholen beim ersten Request mit neuem Token. Ueber HTTP (php-cgi + MariaDB) gegen den Auth-Stub.
 */
final class TotpResetTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const ADMIN2 = '22222222-2222-4222-8222-222222222222';
    private const TARGET = '33333333-3333-4333-8333-333333333333';
    private const RESETTER = '44444444-4444-4444-8444-444444444444';

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

    /** Ein Konto mit `accounts.reset_mfa` und `accounts.view`, aber ohne Wildcard. */
    private function resetter(string $id = self::RESETTER): void
    {
        $this->app->createAccount($id, 'active');
        $this->app->grantPermission($id, 'accounts.reset_mfa');
        $this->app->grantPermission($id, 'accounts.view');
    }

    /** @param array<string,mixed> $claims */
    private function as(string $sub, string $method, string $uri, array $claims = []): array
    {
        return $this->app->request($method, $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub, $claims)] + $this->stub->env());
    }

    private function reset(string $target = self::TARGET, string $actor = self::ADMIN): array
    {
        return $this->as($actor, 'POST', "/api/accounts/$target/reset-totp");
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

    /** Ein Pflichtkonto (MFA-Pflicht, Ticket #15); die Pflicht haengt am Konto, nicht am Test-Token. */
    private function makeMandatory(string $id = self::TARGET): void
    {
        $this->app->pdo()->prepare('UPDATE `accounts` SET `mfa_required` = 1 WHERE `public_user_id` = ?')->execute([$id]);
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
            $this->stub->script('mfa-reset', ['status' => 503, 'body' => []]);
        }
    }

    // ---- Wirkung ----------------------------------------------------------------------------

    public function testDieZuruecksetzungSetztDieSitzungsgrenzeRuftG1AufUndAuditiertOhneDetails(): void
    {
        $r = $this->reset();

        $this->assertSame(200, $r['status']);
        $this->assertNotNull($this->boundary());
        $calls = $this->stub->mfaResetCalls();
        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['signature_valid']);
        $this->assertSame(['event' => 'user.mfa_reset', 'public_user_id' => self::TARGET], $calls[0]['body']);
        $this->assertSame([], $this->stub->revokeSessionsCalls(), 'G1 widerruft die Refresh-Tokens selbst');
        $audit = $this->app->auditLog();
        $this->assertCount(1, $audit);
        $this->assertSame(['account.totp_reset', 'account', self::TARGET, self::ADMIN], [$audit[0]['action'], $audit[0]['object_type'], $audit[0]['object_id'], $audit[0]['actor_account_id']]);
        $this->assertSame([], json_decode((string) $audit[0]['context'], true) ?? []);
    }

    public function testDieAntwortIstDieLesesichtOhneOffenenAufrufNachErfolg(): void
    {
        $r = $this->reset();

        $this->assertSame('active', $r['json']['account']['status']);
        $this->assertSame([], $r['json']['account']['open_calls']);
        $this->assertSame([], $this->app->openCalls(self::TARGET));
    }

    public function testEinAltesTokenLiefertNachDerZuruecksetzungSofort401SessionRevokedEinNeuesLaeuftDurch(): void
    {
        $old = TestTokens::access(self::TARGET, ['iat' => time() - 30]);
        $me = fn (string $token) => $this->app->request('GET', '/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] + $this->stub->env());
        $this->assertSame(200, $me($old)['status']);

        $this->reset();

        $r = $me($old);
        $this->assertSame([401, 'session_revoked'], [$r['status'], $r['json']['code']]);
        $this->assertSame(200, $this->meWithNewToken()['status'], 'ein neuer Login gelingt');
    }

    public function testEinPflichtkontoMussTotpDanachNeuEinrichten(): void
    {
        $this->makeMandatory();
        $this->app->grantPermission(self::TARGET, 'accounts.view');
        $vorher = $this->as(self::TARGET, 'GET', '/api/accounts', ['usedMFA' => true, 'iat' => time() - 30]);
        $this->assertSame(200, $vorher['status']);

        $this->reset();

        $alt = $this->as(self::TARGET, 'GET', '/api/accounts', ['usedMFA' => true, 'iat' => time() - 30]);
        $this->assertSame([401, 'session_revoked'], [$alt['status'], $alt['json']['code']]);
        $neu = $this->as(self::TARGET, 'GET', '/api/accounts', ['usedMFA' => false, 'iat' => time() + 5]);
        $this->assertSame([403, 'mfa_enrollment_required'], [$neu['status'], $neu['json']['code']]);
        $eingerichtet = $this->as(self::TARGET, 'GET', '/api/accounts', ['usedMFA' => true, 'iat' => time() + 5]);
        $this->assertSame(200, $eingerichtet['status']);
    }

    public function testDasKontoBleibtMitStatusUndRollenErhalten(): void
    {
        $this->app->assignRole(self::TARGET, 'lehrkraft');

        $this->reset();

        $target = array_values(array_filter($this->app->accounts(), static fn ($a) => $a['public_user_id'] === self::TARGET))[0];
        $this->assertSame('active', $target['status']);
        $this->assertNull($target['inactive_reason']);
    }

    public function testEinDeaktiviertesKontoDarfZurueckgesetztWerden(): void
    {
        $this->app->setAccountStatus(self::TARGET, 'inactive');

        $r = $this->reset();

        $this->assertSame(200, $r['status']);
        $this->assertSame('inactive', $r['json']['account']['status']);
        $this->assertNotNull($this->boundary());
        $this->assertCount(1, $this->stub->mfaResetCalls());
        $this->assertSame('account.totp_reset', $this->app->auditLog()[0]['action']);
    }

    // ---- Anmeldedienst nicht erreichbar oder lehnt ab ---------------------------------------------

    public function testIstDerAnmeldedienstNichtErreichbarBleibenGrenzeUndOffenerAufrufBestehen(): void
    {
        $this->scriptUnavailable();

        $r = $this->reset();

        $this->assertSame(200, $r['status']);
        $this->assertNotNull($this->boundary());
        $this->assertSame([['operation' => 'reset_totp', 'rejection_code' => null]], $r['json']['account']['open_calls']);
        $this->assertSame(['reset_totp' => null], $this->app->openCalls(self::TARGET));
        $this->assertCount(1, $this->app->auditLog());
    }

    public function testEineFachlicheAblehnungBleibtMitIhremCodeOffen(): void
    {
        $this->stub->script('mfa-reset', ['status' => 400, 'body' => ['error' => 'x', 'code' => 'invalid_request_body']]);

        $r = $this->reset();

        $this->assertSame(200, $r['status']);
        $this->assertSame([['operation' => 'reset_totp', 'rejection_code' => 'invalid_request_body']], $r['json']['account']['open_calls']);
    }

    public function testSolangeDieZuruecksetzungOffenIstLehntDasBackendAuchTokensAusNeuenAnmeldungenAb(): void
    {
        $this->scriptUnavailable(2);
        $this->reset();

        $r = $this->meWithNewToken();

        $this->assertSame([401, 'session_revoked'], [$r['status'], $r['json']['code']]);
        $this->assertSame(['reset_totp' => null], $this->app->openCalls(self::TARGET));
    }

    public function testEinAltesTokenWirdOhneNachholversuchAbgelehnt(): void
    {
        $this->scriptUnavailable();
        $this->reset();

        $r = $this->as(self::TARGET, 'GET', '/api/me', ['iat' => time() - 60]);

        $this->assertSame([401, 'session_revoked'], [$r['status'], $r['json']['code']]);
        $this->assertCount(1, $this->stub->mfaResetCalls(), 'nur der erste Versuch der Verwaltung, keiner fuer ein altes Token');
    }

    public function testDerErsteRequestMitTokenNachDerGrenzeHoltDieOffeneZuruecksetzungNachLehntDasTokenAbDerNeueLoginGelingt(): void
    {
        $this->scriptUnavailable();
        $this->reset();

        $first = $this->meAfterBoundary();
        $second = $this->meWithNewToken();

        $this->assertSame([401, 'session_revoked'], [$first['status'], $first['json']['code']], 'die Grenze rueckt auf den Nachholzeitpunkt: auch das Token, das den Versuch ausloest, ist widerrufen');
        $this->assertSame([], $this->app->openCalls(self::TARGET), 'die Zuruecksetzung ist nachgeholt');
        $this->assertSame(200, $second['status'], 'ein neuer Login nach dem Nachholen gelingt');
        $this->assertCount(2, $this->stub->mfaResetCalls(), 'ein Nachholversuch, danach ist nichts mehr offen');
        $this->assertCount(1, $this->app->auditLog(), 'das Nachholen schreibt keinen Audit-Eintrag');
        $old = $this->as(self::TARGET, 'GET', '/api/me', ['iat' => time() - 60]);
        $this->assertSame(401, $old['status'], 'alte Tokens bleiben widerrufen');
    }

    public function testNachDemNachholenMussEinPflichtkontoTotpNeuEinrichten(): void
    {
        $this->makeMandatory();
        $this->scriptUnavailable();
        $this->reset();

        $triggering = $this->meAfterBoundary();
        $login = $this->as(self::TARGET, 'GET', '/api/accounts', ['usedMFA' => false, 'iat' => time() + 5]);

        $this->assertSame([401, 'session_revoked'], [$triggering['status'], $triggering['json']['code']]);
        $this->assertSame([403, 'mfa_enrollment_required'], [$login['status'], $login['json']['code']], 'die neue Anmeldung hat kein TOTP mehr');
        $this->assertSame([], $this->app->openCalls(self::TARGET));
    }

    public function testLehntDerAnmeldedienstDasNachholenAbBleibtDieSperreUndDerCodeStehtAmAufruf(): void
    {
        $this->scriptUnavailable();
        $this->reset();
        $this->stub->script('mfa-reset', ['status' => 400, 'body' => ['error' => 'x', 'code' => 'invalid_request_body']]);

        $r = $this->meWithNewToken();

        $this->assertSame([401, 'session_revoked'], [$r['status'], $r['json']['code']]);
        $this->assertSame(['reset_totp' => 'invalid_request_body'], $this->app->openCalls(self::TARGET));
    }

    public function testBeiEinemPflichtkontoBleibtDieSperreBisZumNachholenAuchMitMfaFaktor(): void
    {
        $this->makeMandatory();
        $this->scriptUnavailable(2);
        $this->reset();

        $r = $this->as(self::TARGET, 'GET', '/api/me', ['usedMFA' => true, 'iat' => time() + 5]);

        $this->assertSame([401, 'session_revoked'], [$r['status'], $r['json']['code']]);
    }

    public function testAbgleichWiederholenHoltDieZuruecksetzungNachAuchMitNurDemZuruecksetzrecht(): void
    {
        $this->resetter();
        $this->scriptUnavailable();
        $this->reset();

        $r = $this->as(self::RESETTER, 'POST', '/api/accounts/' . self::TARGET . '/resync-login');

        $this->assertSame(200, $r['status']);
        $this->assertSame([], $r['json']['account']['open_calls']);
        $this->assertCount(2, $this->stub->mfaResetCalls());
    }

    public function testEineOffeneZuruecksetzungEinesDeaktiviertenKontosWirdNachgeholt(): void
    {
        $this->app->setAccountStatus(self::TARGET, 'inactive');
        $this->scriptUnavailable();
        $this->reset();

        $r = $this->as(self::ADMIN, 'POST', '/api/accounts/' . self::TARGET . '/resync-login');

        $this->assertSame(200, $r['status']);
        $this->assertSame([], $this->app->openCalls(self::TARGET));
        $this->assertCount(2, $this->stub->mfaResetCalls());
    }

    public function testWiderrufUndZuruecksetzungHolenIhreAufrufeInFesterReihenfolgeNach(): void
    {
        for ($i = 0; $i < 2; $i++) { // der Widerruf scheitert auch beim Nachholversuch, den die Zuruecksetzung anstoesst; der Lauf endet dann, bevor G1 drankommt
            $this->stub->script('revoke-sessions', ['status' => 503, 'body' => []]);
        }
        $this->as(self::ADMIN, 'POST', '/api/accounts/' . self::TARGET . '/revoke-sessions');
        $this->reset();
        $this->assertSame(['revoke_sessions' => null, 'reset_totp' => null], $this->app->openCalls(self::TARGET));

        $this->as(self::ADMIN, 'POST', '/api/accounts/' . self::TARGET . '/resync-login');

        $this->assertSame([], $this->app->openCalls(self::TARGET));
        $this->assertSame(
            ['/internal/users/revoke-sessions', '/internal/users/revoke-sessions', '/internal/users/revoke-sessions', '/internal/users/mfa-reset'],
            array_column($this->stub->calls(), 'path')
        );
    }

    // ---- Rechte und Regeln ------------------------------------------------------------------

    public function testMitRechtUndAccountsViewDarfManZuruecksetzen(): void
    {
        $this->resetter();

        $r = $this->reset(self::TARGET, self::RESETTER);

        $this->assertSame(200, $r['status']);
        $this->assertNotNull($this->boundary());
    }

    public function testDasWiderrufsrechtGenuegtNicht(): void
    {
        $this->app->createAccount(self::RESETTER, 'active');
        $this->app->grantPermission(self::RESETTER, 'accounts.view');
        $this->app->grantPermission(self::RESETTER, 'accounts.revoke_sessions');

        $r = $this->reset(self::TARGET, self::RESETTER);

        $this->assertSame([403, 'forbidden'], [$r['status'], $r['json']['code']]);
        $this->assertNull($this->boundary());
        $this->assertSame([], $this->stub->mfaResetCalls());
        $this->assertSame([], $this->app->auditLog());
    }

    public function testOhneAccountsViewIst403(): void
    {
        $this->app->createAccount(self::RESETTER, 'active');
        $this->app->grantPermission(self::RESETTER, 'accounts.reset_mfa');

        $r = $this->reset(self::TARGET, self::RESETTER);

        $this->assertSame(403, $r['status']);
        $this->assertNull($this->boundary());
    }

    public function testNurFuerKontoseDessenRechteManAlleSelbstHatP2(): void
    {
        $this->resetter();
        $this->app->assignRole(self::TARGET, 'schuladmin');

        $r = $this->reset(self::TARGET, self::RESETTER);

        $this->assertSame([403, 'permission_not_held'], [$r['status'], $r['json']['code']]);
        $this->assertNull($this->boundary());
        $this->assertSame([], $this->stub->mfaResetCalls());
    }

    public function testEinSchuladminDarfEinenAnderenSchuladminZuruecksetzen(): void
    {
        $this->app->createAccount(self::ADMIN2, 'active');
        $this->app->assignRole(self::ADMIN2, 'schuladmin');

        $r = $this->reset(self::ADMIN2);

        $this->assertSame(200, $r['status']);
        $this->assertNotNull($this->boundary(self::ADMIN2));
    }

    public function testDasEigeneKontoIst409SelfTotpReset(): void
    {
        $r = $this->reset(self::ADMIN, self::ADMIN);

        $this->assertSame([409, 'self_totp_reset'], [$r['status'], $r['json']['code']]);
        $this->assertNull($this->boundary(self::ADMIN));
        $this->assertSame([], $this->stub->mfaResetCalls());
        $this->assertSame([], $this->app->auditLog());
    }

    public function testEinNurEingeladenesKontoIst409AccountNotActive(): void
    {
        $this->app->setAccountStatus(self::TARGET, 'invited');

        $r = $this->reset();

        $this->assertSame([409, 'account_not_active'], [$r['status'], $r['json']['code']]);
        $this->assertNull($this->boundary());
        $this->assertSame([], $this->stub->mfaResetCalls());
        $this->assertSame([], $this->app->auditLog());
    }

    public function testEinUnbekanntesKontoIst404(): void
    {
        $r = $this->reset('99999999-9999-4999-8999-999999999999');

        $this->assertSame([404, 'account_not_found'], [$r['status'], $r['json']['code']]);
    }

    // ---- Lesesicht ------------------------------------------------------------------------------

    public function testDieLesesichtNenntDieAktionMitErlaubnisOderBegruendung(): void
    {
        $this->resetter();
        $this->app->assignRole(self::TARGET, 'schuladmin');

        $admin = $this->as(self::ADMIN, 'GET', '/api/accounts/' . self::TARGET . '/access');
        $limited = $this->as(self::RESETTER, 'GET', '/api/accounts/' . self::TARGET . '/access');
        $self = $this->as(self::ADMIN, 'GET', '/api/accounts/' . self::ADMIN . '/access');

        $find = static fn (array $r) => array_values(array_filter($r['json']['account_actions'], static fn ($a) => $a['action'] === 'reset_totp'))[0] ?? null;
        $this->assertSame(['action' => 'reset_totp', 'allowed' => true, 'code' => null, 'reason' => null], $find($admin));
        $this->assertSame(['reset_totp', false, 'permission_not_held'], [$find($limited)['action'], $find($limited)['allowed'], $find($limited)['code']]);
        $this->assertSame([false, 'self_totp_reset'], [$find($self)['allowed'], $find($self)['code']]);
    }

    public function testDieLesesichtErlaubtDieAktionBeiDeaktiviertenUndSperrtSieBeiEingeladenen(): void
    {
        $this->app->setAccountStatus(self::TARGET, 'inactive');
        $inactive = $this->as(self::ADMIN, 'GET', '/api/accounts/' . self::TARGET . '/access');
        $this->app->setAccountStatus(self::TARGET, 'invited');
        $invited = $this->as(self::ADMIN, 'GET', '/api/accounts/' . self::TARGET . '/access');

        $find = static fn (array $r) => array_values(array_filter($r['json']['account_actions'], static fn ($a) => $a['action'] === 'reset_totp'))[0];
        $this->assertTrue($find($inactive)['allowed']);
        $this->assertSame([false, 'account_not_active'], [$find($invited)['allowed'], $find($invited)['code']]);
    }

    public function testDieLesesichtVerrietNichtObTotpEingerichtetIst(): void
    {
        $r = $this->as(self::ADMIN, 'GET', '/api/accounts/' . self::TARGET . '/access');

        $this->assertStringNotContainsString('totp', strtolower((string) json_encode($r['json']['account'])));
        $this->assertArrayNotHasKey('mfa_enabled', $r['json']['account']);
    }

    public function testWerNurZuruecksetzenDarfSiehtDasKontodetail(): void
    {
        $this->resetter();

        $r = $this->as(self::RESETTER, 'GET', '/api/accounts/' . self::TARGET . '/access');

        $this->assertSame(200, $r['status']);
    }
}
