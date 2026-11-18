<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Accounts;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Konto deaktivieren wirkt sofort (Ticket #41, ADR 0002 B1/B2 und Nachtrag #12): POST /api/accounts/{id}/deactivate
 * und die Wirkung auf bereits ausgestellte Tokens, ueber HTTP (php-cgi + MariaDB). Das Spiegeln an den
 * Anmeldedienst steht in AccountLoginSyncTest (Ticket #42); hier ist keiner konfiguriert, die Sperre gilt dann mit "Abgleich offen".
 */
final class AccountDeactivationTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const ADMIN2 = '22222222-2222-4222-8222-222222222222';
    private const TARGET = '33333333-3333-4333-8333-333333333333';
    private const MANAGER = '44444444-4444-4444-8444-444444444444';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
    }

    protected function tearDown(): void
    {
        $this->app->pdo()->exec('DROP TRIGGER IF EXISTS `t41_slow_deactivation`');
    }

    private function admin(string $id = self::ADMIN, string $status = 'active'): void
    {
        $this->app->createAccount($id, $status);
        $this->app->assignRole($id, 'schuladmin');
    }

    /** Ein Konto mit `accounts.deactivate` (und `accounts.view`), aber ohne Wildcard. */
    private function manager(string $id = self::MANAGER): void
    {
        $this->app->createAccount($id, 'active');
        $this->app->grantPermission($id, 'accounts.deactivate');
        $this->app->grantPermission($id, 'accounts.view');
    }

    private function bearer(string $sub, array $claims = []): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub, $claims)];
    }

    private function deactivate(string $target, string $actor = self::ADMIN): array
    {
        return $this->app->request('POST', "/api/accounts/$target/deactivate", $this->bearer($actor));
    }

    private function me(string $token): array
    {
        return $this->app->request('GET', '/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /** @return array<string,mixed> */
    private function row(string $id): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM `accounts` WHERE `public_user_id` = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    // ---- Wirkung ----------------------------------------------------------------------------

    public function testDeaktivierenSetztStatusGrundZeitpunktUndSitzungsgrenzeUndAuditiert(): void
    {
        $this->admin();
        $this->app->createAccount(self::TARGET, 'active');

        $r = $this->deactivate(self::TARGET);

        $this->assertSame(200, $r['status']);
        $row = $this->row(self::TARGET);
        $this->assertSame('inactive', $row['status']);
        $this->assertSame('admin_locked', $row['inactive_reason']);
        $this->assertNotNull($row['inactive_since']);
        $this->assertNotNull($row['sessions_valid_after']);
        $audit = $this->app->auditLog();
        $this->assertCount(1, $audit);
        $this->assertSame(['account.deactivated', 'account', self::TARGET, self::ADMIN], [$audit[0]['action'], $audit[0]['object_type'], $audit[0]['object_id'], $audit[0]['actor_account_id']]);
        $this->assertSame(['reason' => 'admin_locked'], json_decode($audit[0]['context'], true));
    }

    public function testDieAntwortIstDieLesesichtMitDemNeuenStatus(): void
    {
        $this->admin();
        $this->app->createAccount(self::TARGET, 'active', 'ziel@schule.test');

        $r = $this->deactivate(self::TARGET);

        $this->assertSame('inactive', $r['json']['account']['status']);
        $this->assertSame('admin_locked', $r['json']['account']['inactive_reason']);
        $this->assertNotNull($r['json']['account']['inactive_since']);
    }

    public function testEinAltesTokenLiefertNachDerDeaktivierungSofort401MitGrund(): void
    {
        $this->admin();
        $this->app->createAccount(self::TARGET, 'active');
        $token = TestTokens::access(self::TARGET);
        $this->assertSame(200, $this->me($token)['status']);

        $this->deactivate(self::TARGET);

        $r = $this->me($token);
        $this->assertSame(401, $r['status']);
        $this->assertSame('account_inactive', $r['json']['code']);
        $this->assertSame('admin_locked', $r['json']['reason']);
    }

    public function testTokenMitIatVorDerSitzungsgrenzeLiefert401SessionRevoked(): void
    {
        $this->app->createAccount(self::TARGET, 'active');
        $this->app->pdo()->exec("UPDATE `accounts` SET `sessions_valid_after` = FROM_UNIXTIME(" . (time() - 100) . ")");

        $old = $this->me(TestTokens::access(self::TARGET, ['iat' => time() - 200]));
        $fresh = $this->me(TestTokens::access(self::TARGET, ['iat' => time() - 50]));
        $noIat = $this->me(TestTokens::access(self::TARGET, ['iat' => null]));

        $this->assertSame([401, 'session_revoked'], [$old['status'], $old['json']['code']]);
        $this->assertSame(200, $fresh['status']);
        $this->assertSame([401, 'session_revoked'], [$noIat['status'], $noIat['json']['code']], 'ohne iat laesst sich die Grenze nicht pruefen: fail-closed');
    }

    public function testOhneSitzungsgrenzeBleibenAlleTokensGueltig(): void
    {
        $this->app->createAccount(self::TARGET, 'active');

        $this->assertSame(200, $this->me(TestTokens::access(self::TARGET, ['iat' => time() - 800]))['status']);
    }

    // ---- Regeln -----------------------------------------------------------------------------

    public function testOhneRechtAccountsDeactivateIst403Forbidden(): void
    {
        $this->admin();
        $this->app->createAccount(self::TARGET, 'active');
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'accounts.view');

        $r = $this->deactivate(self::TARGET, self::MANAGER);

        $this->assertSame([403, 'forbidden'], [$r['status'], $r['json']['code']]);
        $this->assertSame('active', $this->row(self::TARGET)['status']);
    }

    public function testNurWerAlleEffektivenRechteDesZielsBesitztDarfEsDeaktivierenP2(): void
    {
        $this->manager();
        $this->app->createAccount(self::TARGET, 'active');
        $this->app->grantPermission(self::TARGET, 'accounts.invite');

        $r = $this->deactivate(self::TARGET, self::MANAGER);

        $this->assertSame([403, 'permission_not_held'], [$r['status'], $r['json']['code']]);
        $this->assertSame('active', $this->row(self::TARGET)['status']);
        $this->assertSame([], $this->app->auditLog());
    }

    public function testBesitztDerVerwalterAlleRechteDesZielsDarfEr(): void
    {
        $this->manager();
        $this->app->createAccount(self::TARGET, 'active');
        $this->app->grantPermission(self::TARGET, 'accounts.view');

        $this->assertSame(200, $this->deactivate(self::TARGET, self::MANAGER)['status']);
    }

    public function testNurSchuladminsDeaktivierenSchuladmins(): void
    {
        $this->manager();
        $this->admin(self::ADMIN);
        $this->admin(self::ADMIN2);

        $byManager = $this->deactivate(self::ADMIN2, self::MANAGER);
        $byAdmin = $this->deactivate(self::ADMIN2, self::ADMIN);

        $this->assertSame([403, 'permission_not_held'], [$byManager['status'], $byManager['json']['code']]);
        $this->assertSame(200, $byAdmin['status']);
    }

    public function testSichSelbstDeaktivierenIst409SelfDeactivationAuchFuerAdmins(): void
    {
        $this->admin(self::ADMIN);
        $this->admin(self::ADMIN2);
        $this->manager();

        foreach ([[self::ADMIN, self::ADMIN], [self::MANAGER, self::MANAGER]] as [$actor, $target]) {
            $r = $this->deactivate($target, $actor);
            $this->assertSame([409, 'self_deactivation'], [$r['status'], $r['json']['code']]);
            $this->assertSame('active', $this->row($target)['status']);
        }
    }

    public function testNichtAktiveKontenIst409AccountNotActive(): void
    {
        $this->admin();
        $this->app->createAccount(self::TARGET, 'invited');
        $this->app->createAccount(self::MANAGER, 'inactive');

        foreach ([self::TARGET, self::MANAGER] as $target) {
            $r = $this->deactivate($target);
            $this->assertSame([409, 'account_not_active'], [$r['status'], $r['json']['code']]);
        }
        $this->assertSame([], $this->app->auditLog());
    }

    public function testUnbekanntesKontoIst404(): void
    {
        $this->admin();

        $r = $this->deactivate(self::TARGET);

        $this->assertSame([404, 'account_not_found'], [$r['status'], $r['json']['code']]);
    }

    public function testDerP3BausteinSchuetztDenLetztenAktivenSchuladmin(): void
    {
        // Ueber HTTP ist ein Konto, das den letzten Admin deaktivieren koennte, nie zu haben (nur ein Wildcard-Konto
        // darf Admins deaktivieren, und das ist selbst ein aktiver Admin). Der Fall tritt nur nebenlaeufig auf:
        // siehe die Tests unten. Hier der Baustein selbst, den die Deaktivierung nutzt.
        $this->admin(self::ADMIN);
        $this->admin(self::ADMIN2);

        $r = $this->app->cli('tests/Support/ensure-admin-remains.php', [self::ADMIN, self::ADMIN2]);

        $this->assertSame([3, 'last_admin'], [$r['code'], trim($r['out'])]);
    }

    // ---- Lesesicht und Liste ----------------------------------------------------------------

    /** Die Aktion `deactivate` aus der Kontodetail-Lesesicht von $target, gesehen von $viewer. @return array<string,mixed> */
    private function deactivateAction(string $target, string $viewer = self::ADMIN): array
    {
        $r = $this->app->request('GET', "/api/accounts/$target/access", $this->bearer($viewer));
        $this->assertSame(200, $r['status']);
        $actions = array_column($r['json']['account_actions'], null, 'action');
        return $actions['deactivate'];
    }

    public function testLesesichtErlaubtDasDeaktivierenEinesAktivenKontos(): void
    {
        $this->admin();
        $this->app->createAccount(self::TARGET, 'active');

        $action = $this->deactivateAction(self::TARGET);

        $this->assertTrue($action['allowed']);
        $this->assertNull($action['code']);
        $this->assertNull($action['reason']);
    }

    public function testLesesichtSperrtMitCodeUndBegruendungOhneRechtBeiSichSelbstBeiNichtAktivenUndOhneAlleRechte(): void
    {
        $this->admin();
        $this->admin(self::ADMIN2);
        $this->manager();
        $this->app->grantPermission(self::MANAGER, 'roles.assign');
        $this->app->createAccount(self::TARGET, 'invited');
        $viewer = '55555555-5555-4555-8555-555555555555';
        $this->app->createAccount($viewer, 'active');
        $this->app->grantPermission($viewer, 'accounts.view');
        $this->app->grantPermission($viewer, 'roles.assign');
        $rich = '66666666-6666-4666-8666-666666666666';
        $this->app->createAccount($rich, 'active');
        $this->app->grantPermission($rich, 'accounts.invite');

        $cases = [
            'ohne Recht' => [$this->deactivateAction(self::TARGET, $viewer), 'forbidden'],
            'sich selbst' => [$this->deactivateAction(self::MANAGER, self::MANAGER), 'self_deactivation'],
            'nicht aktiv' => [$this->deactivateAction(self::TARGET), 'account_not_active'],
            'nicht alle Rechte' => [$this->deactivateAction($rich, self::MANAGER), 'permission_not_held'],
            'Schuladmin durch Verwalter' => [$this->deactivateAction(self::ADMIN2, self::MANAGER), 'permission_not_held'],
        ];
        foreach ($cases as $name => [$action, $code]) {
            $this->assertFalse($action['allowed'], $name);
            $this->assertSame($code, $action['code'], $name);
            $this->assertNotEmpty($action['reason'], $name);
        }
    }

    public function testKontodetailNenntGrundUndZeitpunktDerDeaktivierung(): void
    {
        $this->admin();
        $this->app->createAccount(self::TARGET, 'active');
        $this->deactivate(self::TARGET);

        $detail = $this->app->request('GET', '/api/accounts/' . self::TARGET . '/access', $this->bearer(self::ADMIN))['json']['account'];

        $this->assertSame('inactive', $detail['status']);
        $this->assertSame('admin_locked', $detail['inactive_reason']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $detail['inactive_since']);
    }

    public function testKontenlisteNenntDenZeitpunktNurBeiDeaktiviertenKonten(): void
    {
        $this->admin();
        $this->app->createAccount(self::TARGET, 'active');
        $this->deactivate(self::TARGET);

        $rows = $this->app->request('GET', '/api/accounts', $this->bearer(self::ADMIN))['json']['accounts'];

        $byId = array_column($rows, null, 'id');
        $this->assertNull($byId[self::ADMIN]['inactive_since']);
        $this->assertSame('inactive', $byId[self::TARGET]['status']);
        $this->assertNotNull($byId[self::TARGET]['inactive_since']);
    }

    public function testDerKatalogFuehrtAccountsDeactivateInDerGruppeKonten(): void
    {
        $this->admin();

        $catalog = $this->app->request('GET', '/api/permissions', $this->bearer(self::ADMIN))['json'];

        $entry = array_values(array_filter($catalog['permissions'] ?? $catalog, fn ($e) => ($e['key'] ?? null) === 'accounts.deactivate'))[0] ?? null;
        $this->assertNotNull($entry, json_encode($catalog));
        $this->assertSame('Konten', $entry['group']);
    }

    // ---- Nebenlaeufigkeit -------------------------------------------------------------------

    /** Macht das Fenster zwischen Pruefung und Commit gross: Jedes Deaktivieren eines Kontos dauert 1,5 s. */
    private function slowDownDeactivation(): void
    {
        $this->app->pdo()->exec('DROP TRIGGER IF EXISTS `t41_slow_deactivation`');
        $this->app->pdo()->exec("CREATE TRIGGER `t41_slow_deactivation` BEFORE UPDATE ON `accounts` FOR EACH ROW SET @t41 = IF(NEW.`status` = 'inactive' AND OLD.`status` = 'active', SLEEP(1.5), 0)");
    }

    /** @param list<array{string,string}> $deactivations je [Akteur, Zielkonto] */
    private function deactivateConcurrently(array $deactivations): array
    {
        return $this->app->requestConcurrently(array_map(
            fn (array $d) => ['POST', "/api/accounts/$d[1]/deactivate", $this->bearer($d[0]), null],
            $deactivations,
        ));
    }

    /** @return list<string> */
    private function activeAdmins(): array
    {
        return array_map('strval', $this->app->pdo()->query(
            "SELECT a.`public_user_id` FROM `accounts` a JOIN `permission_user_roles` ur ON ur.`user_uuid` = a.`public_user_id`
             JOIN `permission_roles` r ON r.`id` = ur.`role_id` WHERE r.`role_key` = 'schuladmin' AND a.`status` = 'active' ORDER BY a.`id`"
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param list<array{status:int,json:?array}> $responses @return list<string> */
    private function outcomes(array $responses): array
    {
        $outcomes = array_map(fn (array $r) => $r['status'] . ' ' . ($r['json']['code'] ?? '-'), $responses);
        sort($outcomes);
        return $outcomes;
    }

    public function testZweiAdminsDieEinanderGleichzeitigDeaktivierenLassenEinenAktivenUebrig(): void
    {
        $this->admin(self::ADMIN);
        $this->admin(self::ADMIN2);
        $this->slowDownDeactivation();

        $responses = $this->deactivateConcurrently([[self::ADMIN, self::ADMIN2], [self::ADMIN2, self::ADMIN]]);

        $this->assertSame(['200 -', '409 last_admin'], $this->outcomes($responses));
        $this->assertCount(1, $this->activeAdmins());
        $this->assertSame(['account.deactivated'], array_column($this->app->auditLog(), 'action'));
    }

    public function testZweiGleichzeitigeDeaktivierungenDesselbenKontosGelingenNurEinmal(): void
    {
        $this->admin(self::ADMIN);
        $this->admin(self::ADMIN2);
        $this->app->createAccount(self::TARGET, 'active');
        $this->slowDownDeactivation();

        $responses = $this->deactivateConcurrently([[self::ADMIN, self::TARGET], [self::ADMIN2, self::TARGET]]);

        $this->assertSame(['200 -', '409 account_not_active'], $this->outcomes($responses));
        $this->assertSame(['account.deactivated'], array_column($this->app->auditLog(), 'action'));
    }
}
