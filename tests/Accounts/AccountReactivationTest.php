<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Accounts;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\AuthStub;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Konto reaktivieren (Ticket #43, ADR 0002 B2 und Nachtrag #12): `POST /api/accounts/{id}/reactivate`. Umgekehrte Reihenfolge
 * zur Deaktivierung: zuerst `enable-login` am Anmeldedienst (synchron), erst bei Erfolg lokal `active`. HTTP-Tests gegen
 * MariaDB und den Auth-Stub.
 */
final class AccountReactivationTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const TARGET = '33333333-3333-4333-8333-333333333333';
    private const MANAGER = '44444444-4444-4444-8444-444444444444';

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

    private function as(string $sub, string $method, string $uri): array
    {
        return $this->app->request($method, $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)] + $this->stub->env());
    }

    private function deactivate(string $target = self::TARGET): array
    {
        return $this->as(self::ADMIN, 'POST', "/api/accounts/$target/deactivate");
    }

    private function reactivate(string $target = self::TARGET, string $actor = self::ADMIN): array
    {
        return $this->as($actor, 'POST', "/api/accounts/$target/reactivate");
    }

    /** @return array<string,mixed> */
    private function row(string $id): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM `accounts` WHERE `public_user_id` = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    // ---- Wirkung ----------------------------------------------------------------------------

    public function testReaktivierenRuftEnableLoginAufUndSetztDasKontoLokalAufAktiv(): void
    {
        $this->deactivate();

        $r = $this->reactivate();

        $this->assertSame(200, $r['status']);
        $calls = $this->stub->enableLoginCalls();
        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['signature_valid']);
        $this->assertSame(['event' => 'user.enable_login', 'public_user_id' => self::TARGET], $calls[0]['body']);
        $row = $this->row(self::TARGET);
        $this->assertSame('active', $row['status']);
        $this->assertNull($row['inactive_reason']);
        $this->assertNull($row['inactive_since']);
        $this->assertSame([], $this->app->openCalls(self::TARGET));
        $this->assertNotNull($row['sessions_valid_after'], 'die Sitzungsgrenze bleibt stehen');
    }

    public function testReaktivierenSchreibtDenAuditEintragOhneGrund(): void
    {
        $this->deactivate();

        $this->reactivate();

        $audit = $this->app->auditLog();
        $this->assertCount(2, $audit, 'Deaktivierung und Reaktivierung');
        $this->assertSame(['account.reactivated', 'account', self::TARGET, self::ADMIN], [$audit[1]['action'], $audit[1]['object_type'], $audit[1]['object_id'], $audit[1]['actor_account_id']]);
    }

    public function testDieAntwortIstDieLesesichtDesAktivenKontos(): void
    {
        $this->deactivate();

        $r = $this->reactivate();

        $this->assertSame('active', $r['json']['account']['status']);
        $this->assertNull($r['json']['account']['inactive_since']);
        $this->assertNull($r['json']['account']['inactive_reason']);
        $this->assertFalse($r['json']['account']['login_sync_pending']);
    }

    // ---- Pflichttest: alte Tokens bleiben ungueltig -----------------------------------------------

    public function testEinTokenVonVorDerDeaktivierungBleibtNachDerReaktivierungUngueltigEinNeuesFunktioniert(): void
    {
        $old = TestTokens::access(self::TARGET, ['iat' => time() - 30]);
        $me = fn (string $token) => $this->app->request('GET', '/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertSame(200, $me($old)['status']);
        $this->deactivate();
        $this->reactivate();

        $afterOld = $me($old);
        // iat nach der Sekunde der Deaktivierung: ein Token aus derselben Sekunde gilt als widerrufen (B2, `iat <= Grenze`)
        $afterNew = $me(TestTokens::access(self::TARGET, ['iat' => time() + 2]));

        $this->assertSame([401, 'session_revoked'], [$afterOld['status'], $afterOld['json']['code']]);
        $this->assertSame(200, $afterNew['status']);
    }

    public function testEineOffeneAbgleichMarkierungEntfaelltBeiDerReaktivierung(): void
    {
        $this->stub->script('disable-login', ['status' => 503, 'body' => []]);
        $this->deactivate();
        $this->assertSame(['disable_login' => null], $this->app->openCalls(self::TARGET));

        $r = $this->reactivate();

        $this->assertSame(200, $r['status']);
        $this->assertSame([], $this->app->openCalls(self::TARGET));
        $this->assertSame(['deactivate'], array_column($r['json']['account_actions'], 'action'));
    }

    // ---- Anmeldedienst lehnt ab oder ist nicht erreichbar: lokal aendert sich nichts -----------------

    /** @return array<string,array{array<string,mixed>,int,string}> */
    public static function ablehnungenUndAusfaelle(): array
    {
        return [
            'Selbstloeschung' => [['status' => 409, 'body' => ['error' => 'x', 'code' => 'self_deleted']], 409, 'self_deleted'],
            'E-Mail unbestaetigt' => [['status' => 409, 'body' => ['error' => 'x', 'code' => 'email_unconfirmed']], 409, 'email_unconfirmed'],
            'Zeitueberschreitung' => [['delay' => 2.0], 502, 'auth_unavailable'],
            '5xx' => [['status' => 503, 'body' => ['error' => 'down']], 502, 'auth_unavailable'],
            'unlesbare Antwort' => [['status' => 200, 'raw' => '<html>kaputt</html>'], 502, 'auth_unavailable'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ablehnungenUndAusfaelle')]
    public function testLehntDerAnmeldedienstAbOderFaelltErAusBleibtLokalAllesWieEs(array $scripted, int $status, string $code): void
    {
        $this->deactivate();
        $before = $this->row(self::TARGET);
        $auditBefore = count($this->app->auditLog());
        $this->stub->script('enable-login', $scripted);

        $r = $this->reactivate();
        $this->stub->calls(); // php -S bedient nacheinander: wartet, bis eine verzoegerte Antwort ausgeliefert ist

        $this->assertSame([$status, $code], [$r['status'], $r['json']['code']]);
        $this->assertSame($before, $this->row(self::TARGET), 'lokal ist nichts geaendert');
        $this->assertSame($auditBefore, count($this->app->auditLog()), 'kein Audit-Eintrag');
        $this->assertCount(1, $this->stub->enableLoginCalls());
    }

    public function testOhneKonfigurierteAnmeldedienstAdresseIst502(): void
    {
        $this->deactivate();

        $r = $this->app->request('POST', '/api/accounts/' . self::TARGET . '/reactivate', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::ADMIN)]);

        $this->assertSame([502, 'auth_unavailable'], [$r['status'], $r['json']['code']]);
        $this->assertSame('inactive', $this->row(self::TARGET)['status']);
    }

    public function testNachEinemAusfallLaesstSichDieReaktivierungWiederholen(): void
    {
        $this->deactivate();
        $this->stub->script('enable-login', ['status' => 503, 'body' => []]);
        $this->reactivate();

        $r = $this->reactivate();

        $this->assertSame(200, $r['status']);
        $this->assertSame('active', $this->row(self::TARGET)['status']);
    }

    public function testUnbekanntesKontoIst404OhneAufrufAmAnmeldedienst(): void
    {
        $r = $this->reactivate('99999999-9999-4999-8999-999999999999');

        $this->assertSame([404, 'account_not_found'], [$r['status'], $r['json']['code']]);
        $this->assertCount(0, $this->stub->enableLoginCalls());
    }

    // ---- Regeln wie beim Deaktivieren -------------------------------------------------------------

    /** Ein Konto mit `accounts.deactivate` und `accounts.view`, aber ohne Wildcard. */
    private function manager(): void
    {
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'accounts.deactivate');
        $this->app->grantPermission(self::MANAGER, 'accounts.view');
    }

    public function testOhneRechtAccountsDeactivateIst403Forbidden(): void
    {
        $this->deactivate();
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'accounts.view');

        $r = $this->reactivate(self::TARGET, self::MANAGER);

        $this->assertSame([403, 'forbidden'], [$r['status'], $r['json']['code']]);
        $this->assertSame('inactive', $this->row(self::TARGET)['status']);
        $this->assertCount(0, $this->stub->enableLoginCalls());
    }

    public function testNurWerAlleEffektivenRechteDesZielsBesitztDarfEsReaktivierenP2(): void
    {
        $this->manager();
        $this->app->grantPermission(self::TARGET, 'accounts.invite');
        $this->deactivate();

        $r = $this->reactivate(self::TARGET, self::MANAGER);

        $this->assertSame([403, 'permission_not_held'], [$r['status'], $r['json']['code']]);
        $this->assertSame('inactive', $this->row(self::TARGET)['status']);
        $this->assertCount(0, $this->stub->enableLoginCalls());
        $this->assertCount(1, $this->app->auditLog(), 'nur die Deaktivierung');
    }

    public function testBesitztDerVerwalterAlleRechteDesZielsDarfEr(): void
    {
        $this->manager();
        $this->app->grantPermission(self::TARGET, 'accounts.view');
        $this->deactivate();

        $this->assertSame(200, $this->reactivate(self::TARGET, self::MANAGER)['status']);
    }

    public function testNurSchuladminsReaktivierenSchuladmins(): void
    {
        $this->manager();
        $other = '22222222-2222-4222-8222-222222222222';
        $this->app->createAccount($other, 'active');
        $this->app->assignRole($other, 'schuladmin');
        $this->deactivate($other);

        $byManager = $this->reactivate($other, self::MANAGER);
        $byAdmin = $this->reactivate($other, self::ADMIN);

        $this->assertSame([403, 'permission_not_held'], [$byManager['status'], $byManager['json']['code']]);
        $this->assertSame(200, $byAdmin['status']);
    }

    public function testNichtDeaktivierteKontenIst409AccountNotInactive(): void
    {
        $this->app->createAccount(self::MANAGER, 'invited');

        foreach ([self::TARGET, self::MANAGER] as $target) { // aktiv, eingeladen
            $r = $this->reactivate($target);
            $this->assertSame([409, 'account_not_inactive'], [$r['status'], $r['json']['code']]);
        }
        $this->assertCount(0, $this->stub->enableLoginCalls());
        $this->assertSame([], $this->app->auditLog());
    }

    public function testSichSelbstReaktivierenIst409SelfReactivation(): void
    {
        // Ein deaktiviertes Konto kommt ueber HTTP nicht durch die Anmeldung; die Regel gilt am Dienst selbst.
        $this->manager();
        $this->deactivate(self::MANAGER);

        $r = $this->app->cli('tests/Support/reactivate-as.php', [self::MANAGER, self::MANAGER], $this->stub->env());

        $this->assertSame([3, 'self_reactivation'], [$r['code'], trim($r['out'])]);
        $this->assertSame('inactive', $this->row(self::MANAGER)['status']);
        $this->assertCount(0, $this->stub->enableLoginCalls());
    }

    // ---- Lesesicht ------------------------------------------------------------------------------

    /** Die Aktion `reactivate` aus der Kontodetail-Lesesicht von $target, gesehen von $viewer; null, wenn es sie nicht gibt. @return ?array<string,mixed> */
    private function reactivateAction(string $target, string $viewer = self::ADMIN): ?array
    {
        $r = $this->as($viewer, 'GET', "/api/accounts/$target/access");
        $this->assertSame(200, $r['status']);
        return array_column($r['json']['account_actions'], null, 'action')['reactivate'] ?? null;
    }

    public function testLesesichtErlaubtDasReaktivierenEinesDeaktiviertenKontos(): void
    {
        $this->deactivate();

        $action = $this->reactivateAction(self::TARGET);

        $this->assertSame(['reactivate', true, null, null], [$action['action'], $action['allowed'], $action['code'], $action['reason']]);
    }

    public function testLesesichtNenntDieAktionNurBeiDeaktiviertenKonten(): void
    {
        $this->app->createAccount(self::MANAGER, 'invited');

        $this->assertNull($this->reactivateAction(self::TARGET), 'aktiv');
        $this->assertNull($this->reactivateAction(self::MANAGER), 'eingeladen');
    }

    public function testLesesichtSperrtMitCodeUndBegruendungOhneRechtUndOhneAlleRechte(): void
    {
        $this->manager();
        $this->app->grantPermission(self::MANAGER, 'roles.assign');
        $viewer = '55555555-5555-4555-8555-555555555555';
        $this->app->createAccount($viewer, 'active');
        $this->app->grantPermission($viewer, 'accounts.view');
        $this->app->grantPermission($viewer, 'roles.assign');
        $rich = '66666666-6666-4666-8666-666666666666';
        $this->app->createAccount($rich, 'active');
        $this->app->grantPermission($rich, 'accounts.invite');
        $this->deactivate($rich);
        $this->deactivate();

        $cases = [
            'ohne Recht' => [$this->reactivateAction(self::TARGET, $viewer), 'forbidden'],
            'nicht alle Rechte' => [$this->reactivateAction($rich, self::MANAGER), 'permission_not_held'],
        ];
        foreach ($cases as $name => [$action, $code]) {
            $this->assertFalse($action['allowed'], $name);
            $this->assertSame($code, $action['code'], $name);
            $this->assertNotEmpty($action['reason'], $name);
        }
    }
}
