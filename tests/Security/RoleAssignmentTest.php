<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Rollen an Konten vergeben und entziehen (Ticket #34): GET /api/accounts/{id}/access,
 * POST /api/accounts/{id}/roles und POST /api/accounts/{id}/roles/{roleId}/revoke
 * ueber HTTP (php-cgi + MariaDB). Rollen anderer Tests legt jeder Test selbst an und raeumt sie wieder weg.
 */
final class RoleAssignmentTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const VERWALTER = '22222222-2222-4222-8222-222222222222';
    private const TARGET = '33333333-3333-4333-8333-333333333333';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
    }

    protected function tearDown(): void
    {
        $this->app->pdo()->exec("DELETE FROM `permission_roles` WHERE `role_key` LIKE 'r\\_t34\\_%'");
    }

    private function call(string $method, string $uri, string $sub, ?array $json = null): array
    {
        return $this->app->request($method, $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)], $json);
    }

    private function asAdmin(): void
    {
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
    }

    /** Ein Verwalter, der genau diese Rechte hat (als Einzelrechte), z. B. nur roles.assign. */
    private function verwalter(string ...$permissions): void
    {
        $this->app->createAccount(self::VERWALTER, 'active');
        foreach ($permissions as $permission) {
            $this->app->grantPermission(self::VERWALTER, $permission);
        }
    }

    private function target(string $status = 'active'): void
    {
        $this->app->createAccount(self::TARGET, $status);
    }

    /** Legt eine eigene Rolle mit Rechten an; gibt die Rollen-ID zurueck. */
    private function customRole(string $suffix, array $permissions, bool $mfa = true): string
    {
        $id = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
        $pdo = $this->app->pdo();
        $pdo->prepare('INSERT INTO `permission_roles` (`id`, `role_key`, `name`, `is_system`) VALUES (?, ?, ?, 0)')->execute([$id, 'r_t34_' . $suffix, 'Testrolle ' . $suffix]);
        foreach ($permissions as $permission) {
            $pdo->prepare('INSERT INTO `permission_role_permissions` (`role_id`, `permission`) VALUES (?, ?)')->execute([$id, $permission]);
        }
        $pdo->prepare("INSERT INTO `role_settings` (`role_id`, `protection`, `requires_mfa`) VALUES (?, 'custom', ?)")->execute([$id, (int) $mfa]);
        return $id;
    }

    private function roleId(string $key): string
    {
        $stmt = $this->app->pdo()->prepare('SELECT `id` FROM `permission_roles` WHERE `role_key` = ?');
        $stmt->execute([$key]);
        return (string) $stmt->fetchColumn();
    }

    /** @return list<string> Schluessel der Rollen des Kontos */
    private function roleKeysOf(string $sub): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT r.`role_key` FROM `permission_user_roles` ur JOIN `permission_roles` r ON r.`id` = ur.`role_id` WHERE ur.`user_uuid` = ? ORDER BY r.`role_key`');
        $stmt->execute([$sub]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function assign(string $roleId, string $sub = self::ADMIN, string $target = self::TARGET): array
    {
        return $this->call('POST', "/api/accounts/$target/roles", $sub, ['role_id' => $roleId]);
    }

    private function revoke(string $roleId, string $sub = self::ADMIN, string $target = self::TARGET): array
    {
        return $this->call('POST', "/api/accounts/$target/roles/$roleId/revoke", $sub);
    }

    private function access(string $target = self::TARGET, string $sub = self::ADMIN): array
    {
        return $this->call('GET', "/api/accounts/$target/access", $sub);
    }

    /** @return array<string,array<string,mixed>> Rechte der Detailansicht nach Schluessel */
    private function permissionsByKey(array $response): array
    {
        return array_column($response['json']['permissions'], null, 'key');
    }

    // ---- Lesesicht -------------------------------------------------------------------------

    public function testDetailZeigtKontoRollenUndEffektiveRechteMitHerkunft(): void
    {
        $this->asAdmin();
        $this->app->createAccount(self::TARGET, 'active', 'ziel@schule.test', 'ziel');
        $roleId = $this->customRole('a', ['accounts.view', 'accounts.invite']);
        $this->app->assignRole(self::TARGET, 'r_t34_a');
        $this->app->pdo()->prepare("INSERT INTO `permission_user_overrides` (`user_uuid`, `permission`, `mode`) VALUES (?, 'roles.assign', 'added'), (?, 'accounts.invite', 'removed')")
            ->execute([self::TARGET, self::TARGET]);

        $r = $this->access();

        $this->assertSame(200, $r['status']);
        $this->assertSame(
            ['id' => self::TARGET, 'email' => 'ziel@schule.test', 'login_name' => 'ziel', 'status' => 'active', 'inactive_since' => null, 'inactive_reason' => null, 'login_sync_pending' => false, 'login_sync_error' => null, 'open_calls' => [], 'mfa_required' => false],
            $r['json']['account'],
        );
        $this->assertCount(1, $r['json']['roles']);
        $this->assertSame(['id' => $roleId, 'key' => 'r_t34_a', 'name' => 'Testrolle a', 'protection' => 'custom', 'requires_mfa' => true], $r['json']['roles'][0]);
        $this->assertSame(
            [['kind' => 'role', 'role_id' => $roleId, 'role_key' => 'r_t34_a', 'role_name' => 'Testrolle a']],
            $this->permissionsByKey($r)['accounts.view']['sources'],
        );
        $this->assertTrue($this->permissionsByKey($r)['accounts.view']['effective']);
        $this->assertSame('Konten ansehen', $this->permissionsByKey($r)['accounts.view']['name']);
        $this->assertSame('Konten', $this->permissionsByKey($r)['accounts.view']['group']);
        $this->assertSame([['kind' => 'individual']], $this->permissionsByKey($r)['roles.assign']['sources']);
        $this->assertTrue($this->permissionsByKey($r)['roles.assign']['effective']);
        $invite = $this->permissionsByKey($r)['accounts.invite'];
        $this->assertFalse($invite['effective'], 'entzogenes Einzelrecht gilt nicht');
        $this->assertSame(['role', 'withdrawn'], array_column($invite['sources'], 'kind'));
        $this->assertArrayNotHasKey('roles.manage', $this->permissionsByKey($r), 'Rechte ohne Herkunft fehlen');
    }

    public function testDetailEinesSchuladminsFuehrtAlleKatalogrechteUeberDieRolle(): void
    {
        $this->asAdmin();

        $r = $this->access(self::ADMIN);

        $perms = $this->permissionsByKey($r);
        $this->assertEqualsCanonicalizing(['accounts.view', 'accounts.invite', 'accounts.deactivate', 'accounts.revoke_sessions', 'accounts.reset_mfa', 'roles.manage', 'roles.assign', 'permissions.assign', 'school_years.view', 'school_years.manage', 'classes.view', 'classes.manage', 'subjects.view', 'subjects.manage', 'teaching_groups.view', 'teaching_groups.manage'], array_keys($perms));
        foreach ($perms as $p) {
            $this->assertTrue($p['effective']);
            $this->assertSame(['role'], array_column($p['sources'], 'kind'));
            $this->assertSame('schuladmin', $p['sources'][0]['role_key']);
        }
        $this->assertStringNotContainsString('"*"', $r['body']);
        $this->assertIsBool($r['json']['account']['mfa_required']);
    }

    #[DataProvider('verwaltungsrechte')]
    public function testDetailIstMitAccountsViewUndJedemDerDreiVerwaltungsrechteLesbar(string $permission): void
    {
        $this->target();
        $this->verwalter($permission, 'accounts.view');

        $this->assertSame(200, $this->access(self::TARGET, self::VERWALTER)['status']);
    }

    /** @return iterable<string,array{string}> */
    public static function verwaltungsrechte(): iterable
    {
        yield 'roles.manage' => ['roles.manage'];
        yield 'roles.assign' => ['roles.assign'];
        yield 'permissions.assign' => ['permissions.assign'];
    }

    #[DataProvider('verwaltungsrechte')]
    public function testDetailOhneAccountsViewKommt403AuchMitVerwaltungsrecht(string $permission): void
    {
        $this->target();
        $this->verwalter($permission);

        $r = $this->access(self::TARGET, self::VERWALTER);

        $this->assertSame(403, $r['status']);
        $this->assertSame('forbidden', $r['json']['code']);
        $this->assertStringNotContainsString('@', $r['body']);
    }

    public function testSchreibwegOhneAccountsViewLiefertKeineKontodatenAusserIdUndMfa(): void
    {
        $this->target();
        $this->app->pdo()->prepare('UPDATE `accounts` SET `email` = ?, `login_name` = ? WHERE `public_user_id` = ?')->execute(['ziel@schule.test', 'ziel', self::TARGET]);
        $this->verwalter('roles.assign');
        $roleId = $this->customRole('leer', []);

        $r = $this->assign($roleId, self::VERWALTER);

        $this->assertSame(200, $r['status']);
        $this->assertSame(['id', 'mfa_required'], array_keys($r['json']['account']));
        $this->assertSame(self::TARGET, $r['json']['account']['id']);
        $this->assertStringNotContainsString('ziel@schule.test', $r['body']);
        $this->assertSame(['r_t34_leer'], $this->roleKeysOf(self::TARGET));
    }

    public function testSchreibwegMitAccountsViewLiefertAuchKontodaten(): void
    {
        $this->target();
        $this->app->pdo()->prepare('UPDATE `accounts` SET `email` = ?, `login_name` = ? WHERE `public_user_id` = ?')->execute(['ziel@schule.test', 'ziel', self::TARGET]);
        $this->verwalter('roles.assign', 'accounts.view');

        $r = $this->assign($this->customRole('leer', []), self::VERWALTER);

        $this->assertSame(200, $r['status']);
        $this->assertSame('ziel@schule.test', $r['json']['account']['email']);
        $this->assertSame('active', $r['json']['account']['status']);
    }

    public function testDetailOhneVerwaltungsrechtKommt403UndUnbekanntesKontoKommt404(): void
    {
        $this->target();
        $this->verwalter('accounts.view');

        $r = $this->access(self::TARGET, self::VERWALTER);
        $this->assertSame(403, $r['status']);
        $this->assertSame('forbidden', $r['json']['code']);

        $this->asAdmin();
        $r = $this->access('99999999-9999-4999-8999-999999999999');
        $this->assertSame(404, $r['status']);
        $this->assertSame('account_not_found', $r['json']['code']);
    }

    // ---- Vergeben und Entziehen ------------------------------------------------------------

    public function testRolleEinzelnVergebenZeigtSieInDerAntwortUndSchreibtAuditMitSchluesselUndName(): void
    {
        $this->asAdmin();
        $this->target();
        $roleId = $this->customRole('a', ['accounts.view']);

        $r = $this->assign($roleId);

        $this->assertSame(200, $r['status']);
        $this->assertSame(['r_t34_a'], array_column($r['json']['roles'], 'key'));
        $this->assertTrue($this->permissionsByKey($r)['accounts.view']['effective']);
        $this->assertSame(['r_t34_a'], $this->roleKeysOf(self::TARGET));

        $audit = $this->app->auditLog();
        $this->assertCount(1, $audit);
        $this->assertSame('role.assigned', $audit[0]['action']);
        $this->assertSame('account', $audit[0]['actor_type']);
        $this->assertSame(self::ADMIN, $audit[0]['actor_account_id']);
        $this->assertSame('account', $audit[0]['object_type']);
        $this->assertSame(self::TARGET, $audit[0]['object_id']);
        $this->assertSame(['role' => 'r_t34_a', 'name' => 'Testrolle a'], json_decode($audit[0]['context'], true));
    }

    public function testVergebenIstAdditivUndBerueehrtWeitereRollenUndEinzelrechte(): void
    {
        $this->asAdmin();
        $this->target();
        $a = $this->customRole('a', ['accounts.view']);
        $b = $this->customRole('b', ['accounts.invite']);
        $this->app->assignRole(self::TARGET, 'r_t34_a');
        $this->app->grantPermission(self::TARGET, 'roles.assign');

        $r = $this->assign($b);

        $this->assertSame(200, $r['status']);
        $this->assertSame(['r_t34_a', 'r_t34_b'], $this->roleKeysOf(self::TARGET));
        $this->assertSame([['kind' => 'individual']], $this->permissionsByKey($r)['roles.assign']['sources']);
    }

    public function testEntziehenNimmtNurDieEineRolleWeg(): void
    {
        $this->asAdmin();
        $this->target();
        $a = $this->customRole('a', ['accounts.view']);
        $b = $this->customRole('b', ['accounts.invite']);
        $this->app->assignRole(self::TARGET, 'r_t34_a');
        $this->app->assignRole(self::TARGET, 'r_t34_b');
        $this->app->grantPermission(self::TARGET, 'roles.assign');

        $r = $this->revoke($a);

        $this->assertSame(200, $r['status']);
        $this->assertSame(['r_t34_b'], $this->roleKeysOf(self::TARGET));
        $this->assertSame(['r_t34_b'], array_column($r['json']['roles'], 'key'));
        $this->assertSame('individual', $this->permissionsByKey($r)['roles.assign']['sources'][0]['kind'], 'Einzelrechte bleiben');
        $audit = $this->app->auditLog();
        $this->assertSame('role.revoked', $audit[0]['action']);
        $this->assertSame(['role' => 'r_t34_a', 'name' => 'Testrolle a'], json_decode($audit[0]['context'], true));
        $this->assertSame(self::TARGET, $audit[0]['object_id']);
    }

    public function testWiederholtesVergebenOderEntziehenIstEinNoOpOhneZweitenAuditEintrag(): void
    {
        $this->asAdmin();
        $this->target();
        $a = $this->customRole('a', []);

        $this->assertSame(200, $this->assign($a)['status']);
        $this->assertSame(200, $this->assign($a)['status']);
        $this->assertSame(200, $this->revoke($a)['status']);
        $this->assertSame(200, $this->revoke($a)['status']);

        $this->assertSame(['role.assigned', 'role.revoked'], array_column($this->app->auditLog(), 'action'));
    }

    public function testUnbekannteRolleOderKontoKommt404(): void
    {
        $this->asAdmin();
        $this->target();

        $r = $this->assign('99999999-9999-4999-8999-999999999999');
        $this->assertSame(404, $r['status']);
        $this->assertSame('role_not_found', $r['json']['code']);

        $r = $this->revoke('99999999-9999-4999-8999-999999999999');
        $this->assertSame('role_not_found', $r['json']['code']);

        $r = $this->assign($this->roleId('lehrkraft'), self::ADMIN, '99999999-9999-4999-8999-999999999999');
        $this->assertSame(404, $r['status']);
        $this->assertSame('account_not_found', $r['json']['code']);

        $r = $this->call('POST', '/api/accounts/' . self::TARGET . '/roles', self::ADMIN, []);
        $this->assertSame(400, $r['status']);
        $this->assertSame('role_required', $r['json']['code']);
    }

    public function testNurMitRolesAssignSonst403Forbidden(): void
    {
        $this->target();
        $roleId = $this->customRole('a', []);
        $this->verwalter('roles.manage', 'permissions.assign', 'accounts.view');

        foreach ([$this->assign($roleId, self::VERWALTER), $this->revoke($roleId, self::VERWALTER)] as $r) {
            $this->assertSame(403, $r['status']);
            $this->assertSame('forbidden', $r['json']['code']);
        }
        $this->assertSame([], $this->roleKeysOf(self::TARGET));
    }

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('POST', '/api/accounts/' . self::TARGET . '/roles', [], ['role_id' => 'x'])['status']);
        $this->assertSame(401, $this->app->request('GET', '/api/accounts/' . self::TARGET . '/access')['status']);
    }

    // ---- P2: keine Selbsterweiterung -------------------------------------------------------

    public function testVerwalterKannSichSelbstKeineRolleGeben(): void
    {
        $this->verwalter('roles.assign');
        $leer = $this->customRole('leer', []);

        $r = $this->assign($leer, self::VERWALTER, self::VERWALTER);

        $this->assertSame(403, $r['status']);
        $this->assertSame('self_escalation', $r['json']['code']);
        $this->assertSame([], $this->roleKeysOf(self::VERWALTER));
        $this->assertSame([], $this->app->auditLog());
    }

    public function testSchuladminDarfSichUeberRollenRechteZuweisen(): void
    {
        $this->asAdmin();
        $a = $this->customRole('a', ['accounts.view']);

        $r = $this->assign($a, self::ADMIN, self::ADMIN);

        $this->assertSame(200, $r['status']);
        $this->assertContains('r_t34_a', $this->roleKeysOf(self::ADMIN));
    }

    public function testRolleNurVergebenWennAlleRechteDerRolleSelbstEffektivBesessenWerden(): void
    {
        $this->target();
        $this->verwalter('roles.assign', 'accounts.view');
        $ok = $this->customRole('ok', ['accounts.view']);
        $zuviel = $this->customRole('zuviel', ['accounts.view', 'accounts.invite']);

        $r = $this->assign($zuviel, self::VERWALTER);
        $this->assertSame(403, $r['status']);
        $this->assertSame('permission_not_held', $r['json']['code']);
        $this->assertSame([], $this->roleKeysOf(self::TARGET));

        $this->assertSame(200, $this->assign($ok, self::VERWALTER)['status']);
        $this->assertSame(['r_t34_ok'], $this->roleKeysOf(self::TARGET));
    }

    public function testEntziehenIstSymmetrischUndVerlangtAlleRechteDerRolle(): void
    {
        $this->target();
        $this->verwalter('roles.assign', 'accounts.view');
        $zuviel = $this->customRole('zuviel', ['accounts.view', 'accounts.invite']);
        $this->app->assignRole(self::TARGET, 'r_t34_zuviel');

        $r = $this->revoke($zuviel, self::VERWALTER);

        $this->assertSame(403, $r['status']);
        $this->assertSame('permission_not_held', $r['json']['code']);
        $this->assertSame(['r_t34_zuviel'], $this->roleKeysOf(self::TARGET));
    }

    public function testEntzogenesEinzelrechtGiltNichtAlsBesessen(): void
    {
        $this->target();
        $this->verwalter('roles.assign');
        $this->customRole('v', ['accounts.view']);
        $this->app->assignRole(self::VERWALTER, 'r_t34_v');
        $this->app->pdo()->prepare("INSERT INTO `permission_user_overrides` (`user_uuid`, `permission`, `mode`) VALUES (?, 'accounts.view', 'removed')")->execute([self::VERWALTER]);
        $a = $this->customRole('a', ['accounts.view']);

        $r = $this->assign($a, self::VERWALTER);

        $this->assertSame('permission_not_held', $r['json']['code']);
    }

    public function testSchuladminRolleVergebenUndEntziehenNurDurchSchuladmins(): void
    {
        $this->target();
        $this->verwalter('roles.assign', 'roles.manage', 'permissions.assign', 'accounts.view', 'accounts.invite');
        $admin = $this->roleId('schuladmin');

        $r = $this->assign($admin, self::VERWALTER);
        $this->assertSame(403, $r['status']);
        $this->assertSame('permission_not_held', $r['json']['code'], 'auch mit allen Katalogrechten ohne Wildcard');

        $this->asAdmin();
        $this->assertSame(200, $this->assign($admin)['status']);
        $this->assertSame(['schuladmin'], $this->roleKeysOf(self::TARGET));
        $this->assertSame('permission_not_held', $this->revoke($admin, self::VERWALTER)['json']['code']);
    }

    // ---- P6 und Schuladmin ohne Einzelrechte -----------------------------------------------

    public function testKontoMitSchuelerrolleErhaeltKeineWeitereRolle(): void
    {
        $this->asAdmin();
        $this->target();
        $this->app->assignRole(self::TARGET, 'schueler');
        $a = $this->customRole('a', []);

        $r = $this->assign($a);

        $this->assertSame(409, $r['status']);
        $this->assertSame('student_role_exclusive', $r['json']['code']);
        $this->assertSame(['schueler'], $this->roleKeysOf(self::TARGET));
    }

    public function testSchuelerrolleGehtAnKeinKontoMitAnderenRollenOderEinzelrechten(): void
    {
        $this->asAdmin();
        $this->target();
        $schueler = $this->roleId('schueler');
        $a = $this->customRole('a', []);

        $this->app->assignRole(self::TARGET, 'r_t34_a');
        $this->assertSame('student_role_exclusive', $this->assign($schueler)['json']['code']);

        $this->app->pdo()->exec("DELETE FROM `permission_user_roles` WHERE `user_uuid` = '" . self::TARGET . "'");
        $this->app->grantPermission(self::TARGET, 'accounts.view');
        $r = $this->assign($schueler);
        $this->assertSame(409, $r['status']);
        $this->assertSame('student_role_exclusive', $r['json']['code']);
        $this->assertSame([], $this->roleKeysOf(self::TARGET));
    }

    public function testSchuelerrolleAnLeeresKontoUndEntziehenFunktionieren(): void
    {
        $this->asAdmin();
        $this->target();
        $schueler = $this->roleId('schueler');

        $this->assertSame(200, $this->assign($schueler)['status']);
        $this->assertSame(['schueler'], $this->roleKeysOf(self::TARGET));
        $this->assertSame(200, $this->revoke($schueler)['status']);
        $this->assertSame([], $this->roleKeysOf(self::TARGET));
    }

    public function testSchuladminRolleAnKontoMitEinzelrechtenIst409AdminOverridesForbidden(): void
    {
        $this->asAdmin();
        $this->target();
        $this->app->grantPermission(self::TARGET, 'accounts.view');

        $r = $this->assign($this->roleId('schuladmin'));

        $this->assertSame(409, $r['status']);
        $this->assertSame('admin_overrides_forbidden', $r['json']['code']);
        $this->assertSame([], $this->roleKeysOf(self::TARGET));
    }

    // ---- MFA, sofortige Wirkung, Lesesicht der Aktionen ------------------------------------

    public function testMfaRequiredWirdInDerselbenAktionNeuBerechnet(): void
    {
        $this->asAdmin();
        $this->target();
        $mitMfa = $this->customRole('mit', [], true);
        $ohneMfa = $this->customRole('ohne', [], false);
        $mfa = fn () => (int) $this->app->pdo()->query("SELECT `mfa_required` FROM `accounts` WHERE `public_user_id` = '" . self::TARGET . "'")->fetchColumn();

        $this->assertSame(200, $this->assign($ohneMfa)['status']);
        $this->assertSame(0, $mfa());
        $r = $this->assign($mitMfa);
        $this->assertSame(1, $mfa());
        $this->assertTrue($r['json']['account']['mfa_required']);
        $this->revoke($mitMfa);
        $this->assertSame(0, $mfa(), 'ODER ueber die verbleibenden Rollen');
        $this->revoke($ohneMfa);
        $this->assertSame(0, $mfa());
    }

    public function testRechteaenderungenWirkenBeimNaechstenRequestMitDemselbenToken(): void
    {
        $this->asAdmin();
        $this->target();
        $a = $this->customRole('a', ['accounts.view']);
        // Die Rolle erfordert MFA (B3, Ticket #15): das Token traegt den zweiten Faktor, damit hier nur die Rechte zaehlen.
        $token = TestTokens::access(self::TARGET, ['usedMFA' => true]);
        $list = fn () => $this->app->request('GET', '/api/accounts', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token])['status'];

        $this->assertSame(403, $list());
        $this->assign($a);
        $this->assertSame(200, $list());
        $this->revoke($a);
        $this->assertSame(403, $list());
    }

    public function testDetailNenntFuerJedeRolleDieMoeglicheAktionMitBegruendung(): void
    {
        $this->verwalter('roles.assign', 'accounts.view');
        $this->target();
        $ok = $this->customRole('ok', ['accounts.view']);
        $zuviel = $this->customRole('zuviel', ['accounts.invite']);
        $this->app->assignRole(self::TARGET, 'r_t34_ok');

        $r = $this->access(self::TARGET, self::VERWALTER);

        $actions = array_column($r['json']['role_actions'], null, 'role_id');
        $this->assertSame(['role_key' => 'r_t34_ok', 'role_name' => 'Testrolle ok', 'action' => 'revoke', 'allowed' => true, 'code' => null, 'reason' => null], array_diff_key($actions[$ok], ['role_id' => 1]));
        $this->assertSame('assign', $actions[$zuviel]['action']);
        $this->assertFalse($actions[$zuviel]['allowed']);
        $this->assertSame('permission_not_held', $actions[$zuviel]['code']);
        $this->assertNotEmpty($actions[$zuviel]['reason']);
        $this->assertSame('student_role_exclusive', $actions[$this->roleId('schueler')]['code'] ?? null);
        $this->assertCount(count($r['json']['role_actions']), array_unique(array_column($r['json']['role_actions'], 'role_id')));
        $this->assertSame(6 + 2, count($r['json']['role_actions']), 'eine Aktion je Rolle der Schulinstanz');
    }

    public function testDetailMeldetSelbstZuweisungAlsGesperrteAktion(): void
    {
        $this->verwalter('roles.assign', 'accounts.view');
        $a = $this->customRole('a', []);

        $r = $this->access(self::VERWALTER, self::VERWALTER);

        $actions = array_column($r['json']['role_actions'], null, 'role_id');
        $this->assertFalse($actions[$a]['allowed']);
        $this->assertSame('self_escalation', $actions[$a]['code']);
    }
}
