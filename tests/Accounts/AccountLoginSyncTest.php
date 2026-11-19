<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Accounts;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\AuthStub;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Deaktivierung an den Anmeldedienst spiegeln, Abgleich wiederholen (Ticket #42, ADR 0002 B2 und Nachtrag #12):
 * `disable-login` mit `admin_locked` nach der lokalen Sperre, Markierung "Abgleich offen" bei Ausfall und
 * `POST /api/accounts/{id}/resync-login`. HTTP-Tests gegen MariaDB und den Auth-Stub.
 */
final class AccountLoginSyncTest extends TestCase
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

    private function deactivate(string $target = self::TARGET, string $actor = self::ADMIN): array
    {
        return $this->as($actor, 'POST', "/api/accounts/$target/deactivate");
    }

    /** @return array<string,mixed> */
    private function row(string $id): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM `accounts` WHERE `public_user_id` = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    // ---- Spiegeln ---------------------------------------------------------------------------

    public function testNachDerDeaktivierungIstDisableLoginMitAdminLockedAufgerufen(): void
    {
        $r = $this->deactivate();

        $this->assertSame(200, $r['status']);
        $calls = $this->stub->disableLoginCalls();
        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['signature_valid']);
        $this->assertSame(['event' => 'user.self_deleted', 'public_user_id' => self::TARGET, 'reason' => 'admin_locked'], $calls[0]['body']);
    }

    public function testErfolgreicherAbgleichLaesstDieMarkierungNichtEntstehen(): void
    {
        $r = $this->deactivate();

        $this->assertFalse($r['json']['account']['login_sync_pending']);
        $this->assertSame(0, (int) $this->row(self::TARGET)['login_sync_pending']);
        $this->assertSame(['deactivate', 'reactivate'], array_column($r['json']['account_actions'], 'action'));
    }

    // ---- Ausfall des Anmeldedienstes: lokale Sperre bleibt, "Abgleich offen" --------------------

    /** @return array<string,array{array<string,mixed>}> */
    public static function ausfaelle(): array
    {
        return [
            'Zeitueberschreitung' => [['delay' => 2.0]],
            '5xx' => [['status' => 503, 'body' => ['error' => 'down']]],
            'unlesbare Antwort' => [['status' => 200, 'raw' => '<html>kaputt</html>']],
            'fachliche Ablehnung' => [['status' => 404, 'body' => ['error' => 'x', 'code' => 'user_not_found']]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ausfaelle')]
    public function testEinAusfallMachtDieLokaleSperreNichtRueckgaengigUndMarkiertAbgleichOffen(array $scripted): void
    {
        $this->stub->script('disable-login', $scripted);

        $r = $this->deactivate();
        $this->stub->calls(); // php -S bedient nacheinander: wartet, bis eine verzoegerte Antwort ausgeliefert ist

        $this->assertSame(200, $r['status']);
        $this->assertSame('inactive', $r['json']['account']['status']);
        $this->assertTrue($r['json']['account']['login_sync_pending']);
        $this->assertContains('resync_login', array_column($r['json']['account_actions'], 'action'));
        $row = $this->row(self::TARGET);
        $this->assertSame('inactive', $row['status']);
        $this->assertSame(1, (int) $row['login_sync_pending']);
        $this->assertCount(1, $this->stub->disableLoginCalls());
        $this->assertCount(1, $this->app->auditLog(), 'die Deaktivierung gilt als erfolgt und ist auditiert');
        $token = $this->app->request('GET', '/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::TARGET)]);
        $this->assertSame(401, $token['status']);
    }

    public function testOhneKonfigurierteAnmeldedienstAdresseGiltDieSperreMitAbgleichOffen(): void
    {
        $r = $this->app->request('POST', '/api/accounts/' . self::TARGET . '/deactivate', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::ADMIN)]);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['json']['account']['login_sync_pending']);
    }

    public function testKontenListeKennzeichnetAbgleichOffen(): void
    {
        $this->stub->script('disable-login', ['status' => 503, 'body' => []]);
        $this->deactivate();
        $this->app->createAccount(self::MANAGER, 'active', 'andere@schule.test');

        $accounts = array_column($this->as(self::ADMIN, 'GET', '/api/accounts')['json']['accounts'], null, 'id');

        $this->assertTrue($accounts[self::TARGET]['login_sync_pending']);
        $this->assertFalse($accounts[self::MANAGER]['login_sync_pending']);
        $this->assertFalse($accounts[self::ADMIN]['login_sync_pending']);
    }

    // ---- Abgleich wiederholen ------------------------------------------------------------------

    private function resync(string $target = self::TARGET, string $actor = self::ADMIN): array
    {
        return $this->as($actor, 'POST', "/api/accounts/$target/resync-login");
    }

    private function deactivateWithOutage(): void
    {
        $this->stub->script('disable-login', ['status' => 503, 'body' => []]);
        $this->deactivate();
        $this->assertSame(1, (int) $this->row(self::TARGET)['login_sync_pending']);
    }

    public function testAbgleichWiederholenRuftDisableLoginErneutAufUndEntferntDieMarkierung(): void
    {
        $this->deactivateWithOutage();

        $r = $this->resync();

        $this->assertSame(200, $r['status']);
        $this->assertFalse($r['json']['account']['login_sync_pending']);
        $this->assertSame(0, (int) $this->row(self::TARGET)['login_sync_pending']);
        $this->assertSame(['deactivate', 'reactivate'], array_column($r['json']['account_actions'], 'action'));
        $calls = $this->stub->disableLoginCalls();
        $this->assertCount(2, $calls);
        $this->assertSame(['event' => 'user.self_deleted', 'public_user_id' => self::TARGET, 'reason' => 'admin_locked'], $calls[1]['body']);
        $this->assertSame('inactive', $this->row(self::TARGET)['status']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ausfaelle')]
    public function testScheitertDasWiederholenBleibtDieMarkierungUndDieAntwortIstFehler(array $scripted): void
    {
        $this->deactivateWithOutage();
        $this->stub->script('disable-login', $scripted);

        $r = $this->resync();
        $this->stub->calls();

        if (($scripted['status'] ?? 0) === 404) {
            $this->assertSame([404, 'user_not_found'], [$r['status'], $r['json']['code']], 'fachliche Ablehnungen gibt die API mit Status und Code weiter');
        } else {
            $this->assertSame([502, 'auth_unavailable'], [$r['status'], $r['json']['code']]);
        }
        $this->assertSame(1, (int) $this->row(self::TARGET)['login_sync_pending']);
        $this->assertSame('inactive', $this->row(self::TARGET)['status']);
    }

    public function testDasNachholenErzeugtKeinenAuditEintrag(): void
    {
        $this->deactivateWithOutage();
        $before = count($this->app->auditLog());

        $this->resync();

        $this->assertSame($before, count($this->app->auditLog()));
    }

    public function testAbgleichWiederholenBrauchtAccountsDeactivate(): void
    {
        $this->deactivateWithOutage();
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'accounts.view');

        $r = $this->resync(self::TARGET, self::MANAGER);

        $this->assertSame(403, $r['status']);
        $this->assertSame(1, (int) $this->row(self::TARGET)['login_sync_pending']);
        $this->assertCount(1, $this->stub->disableLoginCalls());
    }

    public function testEinVerwalterMitAccountsDeactivateSiehtDenKnopfErlaubtUndHoltDenAbgleichNach(): void
    {
        $this->deactivateWithOutage();
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'accounts.deactivate');
        $this->app->grantPermission(self::MANAGER, 'accounts.view');
        $this->app->grantPermission(self::MANAGER, 'roles.assign'); // Kontodetail setzt eines der Verwaltungsrechte voraus

        $view = $this->as(self::MANAGER, 'GET', '/api/accounts/' . self::TARGET . '/access');
        $action = array_values(array_filter($view['json']['account_actions'], static fn ($a) => $a['action'] === 'resync_login'))[0];
        $this->assertTrue($action['allowed']);

        $this->assertSame(200, $this->resync(self::TARGET, self::MANAGER)['status']);
    }

    public function testOhneOffenenAbgleichLiefertDasWiederholen409(): void
    {
        $this->deactivate(); // Abgleich gelang
        $this->assertSame([409, 'sync_not_pending'], $this->codeOf($this->resync()));
        $this->assertCount(1, $this->stub->disableLoginCalls());

        $this->app->createAccount(self::MANAGER, 'active');
        $this->assertSame([409, 'sync_not_pending'], $this->codeOf($this->resync(self::MANAGER)), 'aktives Konto');
    }

    public function testUnbekanntesKontoBeimWiederholenIst404(): void
    {
        $this->assertSame([404, 'account_not_found'], $this->codeOf($this->resync('99999999-9999-4999-8999-999999999999')));
    }

    // ---- Fachliche Ablehnung: der Code bleibt am Konto (Review #12, Q26) ------------------------

    private function rejection(string $code = 'user_not_found', int $status = 404): array
    {
        return ['status' => $status, 'body' => ['error' => 'x', 'code' => $code]];
    }

    public function testEineFachlicheAblehnungBeimSpiegelnHaeltDenCodeFestUndNenntIhnInDerLesesicht(): void
    {
        $this->stub->script('disable-login', $this->rejection());

        $r = $this->deactivate();

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['json']['account']['login_sync_pending']);
        $this->assertSame('user_not_found', $r['json']['account']['login_sync_error']);
        $this->assertSame('user_not_found', $this->row(self::TARGET)['login_sync_error']);
        $detail = $this->as(self::ADMIN, 'GET', '/api/accounts/' . self::TARGET . '/access');
        $this->assertSame('user_not_found', $detail['json']['account']['login_sync_error']);
    }

    public function testNichterreichbarkeitSetztKeinenCode(): void
    {
        $this->stub->script('disable-login', ['status' => 503, 'body' => []]);

        $r = $this->deactivate();

        $this->assertTrue($r['json']['account']['login_sync_pending']);
        $this->assertNull($r['json']['account']['login_sync_error']);
        $this->assertNull($this->row(self::TARGET)['login_sync_error']);
    }

    public function testAbgleichWiederholenAktualisiertDenGespeichertenCodeBeiAblehnung(): void
    {
        $this->stub->script('disable-login', $this->rejection());
        $this->deactivate();
        $this->stub->script('disable-login', $this->rejection('other_reason', 409));

        $r = $this->resync();

        $this->assertSame([409, 'other_reason'], $this->codeOf($r), 'die Ablehnung geht wie bisher mit Status und Code weiter');
        $row = $this->row(self::TARGET);
        $this->assertSame('other_reason', $row['login_sync_error']);
        $this->assertSame(1, (int) $row['login_sync_pending']);
        $detail = $this->as(self::ADMIN, 'GET', '/api/accounts/' . self::TARGET . '/access');
        $this->assertSame('other_reason', $detail['json']['account']['login_sync_error']);
    }

    public function testErfolgreichesWiederholenLeertDenCode(): void
    {
        $this->stub->script('disable-login', $this->rejection());
        $this->deactivate();

        $r = $this->resync();

        $this->assertSame(200, $r['status']);
        $this->assertNull($r['json']['account']['login_sync_error']);
        $this->assertNull($this->row(self::TARGET)['login_sync_error']);
    }

    public function testDieReaktivierungLeertDenCode(): void
    {
        $this->stub->script('disable-login', $this->rejection());
        $this->deactivate();

        $this->as(self::ADMIN, 'POST', '/api/accounts/' . self::TARGET . '/reactivate');

        $row = $this->row(self::TARGET);
        $this->assertSame('active', $row['status']);
        $this->assertNull($row['login_sync_error']);
    }

    public function testDieMigration0011IstWiederholbarUndBehaeltDenCode(): void
    {
        $this->stub->script('disable-login', $this->rejection());
        $this->deactivate();

        $this->app->rerunMigrations('0011_account_login_sync_error.php');

        $this->assertSame('user_not_found', $this->row(self::TARGET)['login_sync_error']);
    }

    public function testDieMigrationIstWiederholbarUndBehaeltDieMarkierung(): void
    {
        $this->stub->script('disable-login', ['status' => 503, 'body' => []]);
        $this->deactivate();

        $this->app->rerunMigrations('0010_account_login_sync_pending.php');

        $this->assertSame(1, (int) $this->row(self::TARGET)['login_sync_pending']);
    }

    /** @return array{int,mixed} */
    private function codeOf(array $r): array
    {
        return [$r['status'], $r['json']['code'] ?? null];
    }
}
