<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Security;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Einzelrechte gewaehren, entziehen und zuruecksetzen (Ticket #36):
 * POST /api/accounts/{id}/permissions/grant|withdraw|clear {permission} und die Lesesicht
 * GET /api/accounts/{id}/access (`permission_actions`) ueber HTTP (php-cgi + MariaDB).
 */
final class PermissionOverrideTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const VERWALTER = '22222222-2222-4222-8222-222222222222';
    private const TARGET = '33333333-3333-4333-8333-333333333333';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->pdo()->exec("DELETE FROM `permission_user_overrides`");
    }

    protected function tearDown(): void
    {
        $this->app->pdo()->exec("DELETE FROM `permission_roles` WHERE `role_key` LIKE 'r\_t36\_%'");
        $this->app->pdo()->exec("DELETE FROM `permission_user_overrides`");
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

    /** Ein Verwalter, der genau diese Rechte hat (als Einzelrechte), z. B. nur permissions.assign. */
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

    private function customRole(string $suffix, array $permissions): string
    {
        $id = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
        $pdo = $this->app->pdo();
        $pdo->prepare('INSERT INTO `permission_roles` (`id`, `role_key`, `name`, `is_system`) VALUES (?, ?, ?, 0)')->execute([$id, 'r_t36_' . $suffix, 'Testrolle ' . $suffix]);
        foreach ($permissions as $permission) {
            $pdo->prepare('INSERT INTO `permission_role_permissions` (`role_id`, `permission`) VALUES (?, ?)')->execute([$id, $permission]);
        }
        $pdo->prepare("INSERT INTO `role_settings` (`role_id`, `protection`, `requires_mfa`) VALUES (?, 'custom', 1)")->execute([$id]);
        return $id;
    }

    private function act(string $action, ?string $permission, string $sub = self::ADMIN, string $target = self::TARGET): array
    {
        return $this->call('POST', "/api/accounts/$target/permissions/$action", $sub, $permission === null ? [] : ['permission' => $permission]);
    }

    private function grant(?string $permission, string $sub = self::ADMIN, string $target = self::TARGET): array
    {
        return $this->act('grant', $permission, $sub, $target);
    }

    private function withdraw(?string $permission, string $sub = self::ADMIN, string $target = self::TARGET): array
    {
        return $this->act('withdraw', $permission, $sub, $target);
    }

    private function clear(?string $permission, string $sub = self::ADMIN, string $target = self::TARGET): array
    {
        return $this->act('clear', $permission, $sub, $target);
    }

    /** @return array<string,string> Einzelrechte des Kontos: Recht => added|removed */
    private function overridesOf(string $sub): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT `permission`, `mode` FROM `permission_user_overrides` WHERE `user_uuid` = ? ORDER BY `permission`');
        $stmt->execute([$sub]);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /** @return array<string,array<string,mixed>> */
    private function permissionsByKey(array $response): array
    {
        return array_column($response['json']['permissions'], null, 'key');
    }

    // ---- Gewaehren, Entziehen, Zuruecksetzen -----------------------------------------------

    public function testGewaehrenSetztEinzelrechtZeigtHerkunftUndSchreibtAuditMitSchluesselUndModus(): void
    {
        $this->asAdmin();
        $this->target();

        $r = $this->grant('accounts.view');

        $this->assertSame(200, $r['status']);
        $this->assertSame(['accounts.view' => 'added'], $this->overridesOf(self::TARGET));
        $view = $this->permissionsByKey($r)['accounts.view'];
        $this->assertTrue($view['effective']);
        $this->assertSame([['kind' => 'individual']], $view['sources']);
        $this->assertSame(200, $this->call('GET', '/api/accounts', self::TARGET)['status'], 'wirkt beim naechsten Request');

        $audit = $this->app->auditLog();
        $this->assertCount(1, $audit);
        $this->assertSame('permission.granted', $audit[0]['action']);
        $this->assertSame(self::ADMIN, $audit[0]['actor_account_id']);
        $this->assertSame('account', $audit[0]['object_type']);
        $this->assertSame(self::TARGET, $audit[0]['object_id']);
        $this->assertSame(['permission' => 'accounts.view', 'mode' => 'added'], json_decode($audit[0]['context'], true));
    }

    public function testEntziehenSperrtEinRechtDasEineRolleGibtUndZeigtHerkunftEntzogen(): void
    {
        $this->asAdmin();
        $this->target();
        $roleId = $this->customRole('a', ['accounts.view']);
        $this->app->assignRole(self::TARGET, 'r_t36_a');

        $r = $this->withdraw('accounts.view');

        $this->assertSame(200, $r['status']);
        $this->assertSame(['accounts.view' => 'removed'], $this->overridesOf(self::TARGET));
        $view = $this->permissionsByKey($r)['accounts.view'];
        $this->assertFalse($view['effective']);
        $this->assertSame(['role', 'withdrawn'], array_column($view['sources'], 'kind'));
        $this->assertSame(403, $this->call('GET', '/api/accounts', self::TARGET)['status']);
        $audit = $this->app->auditLog();
        $this->assertSame('permission.withdrawn', $audit[0]['action']);
        $this->assertSame(['permission' => 'accounts.view', 'mode' => 'removed'], json_decode($audit[0]['context'], true));
    }

    public function testZuruecksetzenEntferntDasEinzelrechtUndEsGiltWasDieRollenErgeben(): void
    {
        $this->asAdmin();
        $this->target();
        $this->customRole('a', ['accounts.view']);
        $this->app->assignRole(self::TARGET, 'r_t36_a');
        $this->withdraw('accounts.view');

        $r = $this->clear('accounts.view');

        $this->assertSame(200, $r['status']);
        $this->assertSame([], $this->overridesOf(self::TARGET));
        $view = $this->permissionsByKey($r)['accounts.view'];
        $this->assertTrue($view['effective']);
        $this->assertSame(['role'], array_column($view['sources'], 'kind'));
        $audit = $this->app->auditLog();
        $this->assertSame(['permission.withdrawn', 'permission.override_cleared'], array_column($audit, 'action'));
        $this->assertSame(['permission' => 'accounts.view', 'mode' => 'removed'], json_decode($audit[1]['context'], true), 'Modus = der zurueckgesetzte');
    }

    public function testZuruecksetzenEinesGewaehrtenEinzelrechtsNimmtEsWiederWeg(): void
    {
        $this->asAdmin();
        $this->target();
        $this->grant('accounts.view');

        $r = $this->clear('accounts.view');

        $this->assertSame([], $this->overridesOf(self::TARGET));
        $this->assertArrayNotHasKey('accounts.view', $this->permissionsByKey($r));
        $this->assertSame(['permission' => 'accounts.view', 'mode' => 'added'], json_decode($this->app->auditLog()[1]['context'], true));
    }

    public function testGewaehrenErsetztEinEntzogenesEinzelrechtUndEntziehenEinGewaehrtes(): void
    {
        $this->asAdmin();
        $this->target();
        $this->withdraw('accounts.view');
        $this->grant('accounts.view');
        $this->assertSame(['accounts.view' => 'added'], $this->overridesOf(self::TARGET));
        $this->withdraw('accounts.view');
        $this->assertSame(['accounts.view' => 'removed'], $this->overridesOf(self::TARGET));
    }

    public function testEinzelrechteSindEinzelnUndBerueherenRollenUndAndereEinzelrechte(): void
    {
        $this->asAdmin();
        $this->target();
        $this->customRole('a', ['accounts.invite']);
        $this->app->assignRole(self::TARGET, 'r_t36_a');
        $this->grant('accounts.view');

        $this->grant('roles.assign');
        $this->clear('roles.assign');

        $this->assertSame(['accounts.view' => 'added'], $this->overridesOf(self::TARGET));
        $stmt = $this->app->pdo()->prepare('SELECT COUNT(*) FROM `permission_user_roles` WHERE `user_uuid` = ?');
        $stmt->execute([self::TARGET]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testWiederholenIstEinNoOpOhneZweitenAuditEintrag(): void
    {
        $this->asAdmin();
        $this->target();

        $this->assertSame(200, $this->grant('accounts.view')['status']);
        $this->assertSame(200, $this->grant('accounts.view')['status']);
        $this->assertSame(200, $this->clear('accounts.view')['status']);
        $this->assertSame(200, $this->clear('accounts.view')['status']);
        $this->assertSame(200, $this->withdraw('accounts.invite')['status']);
        $this->assertSame(200, $this->withdraw('accounts.invite')['status']);

        $this->assertSame(['permission.granted', 'permission.override_cleared', 'permission.withdrawn'], array_column($this->app->auditLog(), 'action'));
    }

    // ---- Berechtigung und P2 ---------------------------------------------------------------

    public function testNurMitPermissionsAssignSonst403Forbidden(): void
    {
        $this->target();
        $this->verwalter('roles.manage', 'roles.assign', 'accounts.view');

        foreach ([$this->grant('accounts.view', self::VERWALTER), $this->withdraw('accounts.view', self::VERWALTER), $this->clear('accounts.view', self::VERWALTER)] as $r) {
            $this->assertSame(403, $r['status']);
            $this->assertSame('forbidden', $r['json']['code']);
        }
        $this->assertSame([], $this->overridesOf(self::TARGET));
        $this->assertSame([], $this->app->auditLog());
    }

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('POST', '/api/accounts/' . self::TARGET . '/permissions/grant', [], ['permission' => 'accounts.view'])['status']);
    }

    public function testVerwalterKannSichSelbstKeinRechtGeben(): void
    {
        $this->verwalter('permissions.assign');

        $r = $this->grant('roles.assign', self::VERWALTER, self::VERWALTER);

        $this->assertSame(403, $r['status']);
        $this->assertSame('self_escalation', $r['json']['code']);
        $this->assertSame(['permissions.assign' => 'added'], $this->overridesOf(self::VERWALTER));
        $this->assertSame([], $this->app->auditLog());
    }

    public function testSichSelbstEntziehenOderZuruecksetzenOhneWildcardIstSelbsterweiterung(): void
    {
        $this->verwalter('permissions.assign', 'accounts.view');

        foreach ([$this->withdraw('accounts.view', self::VERWALTER, self::VERWALTER), $this->clear('accounts.view', self::VERWALTER, self::VERWALTER)] as $r) {
            $this->assertSame(403, $r['status']);
            $this->assertSame('self_escalation', $r['json']['code']);
        }
        $this->assertSame(['accounts.view' => 'added', 'permissions.assign' => 'added'], $this->overridesOf(self::VERWALTER));
    }

    public function testAlleDreiHandlungenNurFuerRechteDieDerVerwalterSelbstEffektivBesitzt(): void
    {
        $this->target();
        $this->verwalter('permissions.assign', 'accounts.view');
        $this->app->grantPermission(self::TARGET, 'accounts.invite');

        foreach ([$this->grant('roles.assign', self::VERWALTER), $this->withdraw('accounts.invite', self::VERWALTER), $this->clear('accounts.invite', self::VERWALTER)] as $r) {
            $this->assertSame(403, $r['status']);
            $this->assertSame('permission_not_held', $r['json']['code']);
        }
        $this->assertSame(['accounts.invite' => 'added'], $this->overridesOf(self::TARGET));

        $this->assertSame(200, $this->grant('accounts.view', self::VERWALTER)['status']);
        $this->assertSame(200, $this->withdraw('accounts.view', self::VERWALTER)['status']);
        $this->assertSame(200, $this->clear('accounts.view', self::VERWALTER)['status']);
    }

    public function testZuruecksetzenEinesEntzogenenEinzelrechtsZaehltAlsGewaehrenUndVerlangtDasRecht(): void
    {
        $this->target();
        $this->verwalter('permissions.assign');
        $this->customRole('a', ['accounts.view']);
        $this->app->assignRole(self::TARGET, 'r_t36_a');
        $this->app->pdo()->prepare("INSERT INTO `permission_user_overrides` (`user_uuid`, `permission`, `mode`) VALUES (?, 'accounts.view', 'removed')")->execute([self::TARGET]);

        $r = $this->clear('accounts.view', self::VERWALTER);

        $this->assertSame(403, $r['status']);
        $this->assertSame('permission_not_held', $r['json']['code']);
        $this->assertSame(['accounts.view' => 'removed'], $this->overridesOf(self::TARGET));

        $this->app->grantPermission(self::VERWALTER, 'accounts.view');
        $this->assertSame(200, $this->clear('accounts.view', self::VERWALTER)['status']);
        $this->assertSame([], $this->overridesOf(self::TARGET));
    }

    public function testEinEntzogenesEigenesEinzelrechtZaehltNichtAlsBesessen(): void
    {
        $this->target();
        $this->verwalter('permissions.assign');
        $this->customRole('v', ['accounts.view']);
        $this->app->assignRole(self::VERWALTER, 'r_t36_v');
        $this->app->pdo()->prepare("INSERT INTO `permission_user_overrides` (`user_uuid`, `permission`, `mode`) VALUES (?, 'accounts.view', 'removed')")->execute([self::VERWALTER]);

        $this->assertSame('permission_not_held', $this->grant('accounts.view', self::VERWALTER)['json']['code']);
    }

    public function testSchuladminMitWildcardDarfAnderenJedesKatalogrechtGebenEntziehenUndZuruecksetzen(): void
    {
        $this->asAdmin();
        $this->target();

        $this->assertSame(200, $this->grant('roles.manage')['status']);
        $this->assertSame(200, $this->withdraw('roles.manage')['status']);
        $this->assertSame(200, $this->clear('roles.manage')['status']);
    }

    // ---- P6 und Schuladmin ohne Einzelrechte -----------------------------------------------

    public function testKontoMitSchuelerrolleErhaeltKeineEinzelrechte(): void
    {
        $this->asAdmin();
        $this->target();
        $this->app->assignRole(self::TARGET, 'schueler');

        foreach ([$this->grant('accounts.view'), $this->withdraw('accounts.view')] as $r) {
            $this->assertSame(409, $r['status']);
            $this->assertSame('student_role_exclusive', $r['json']['code']);
        }
        $this->assertSame([], $this->overridesOf(self::TARGET));
        $this->assertSame([], $this->app->auditLog());
    }

    public function testKontoMitRolleSchuladminErhaeltKeineEinzelrechte(): void
    {
        $this->asAdmin();
        $this->app->createAccount(self::TARGET, 'active');
        $this->app->assignRole(self::TARGET, 'schuladmin');

        foreach ([$this->grant('accounts.view'), $this->withdraw('accounts.view')] as $r) {
            $this->assertSame(409, $r['status']);
            $this->assertSame('admin_overrides_forbidden', $r['json']['code']);
        }
        $this->assertSame([], $this->overridesOf(self::TARGET));
    }

    // ---- Eingabe ---------------------------------------------------------------------------

    public function testRechtAusserhalbDesKatalogsIst400UndFehlendesRechtIst400(): void
    {
        $this->asAdmin();
        $this->target();

        foreach (['grant', 'withdraw', 'clear'] as $action) {
            $r = $this->act($action, 'grades.delete');
            $this->assertSame(400, $r['status']);
            $this->assertSame('unknown_permission', $r['json']['code']);
            $r = $this->act($action, '*');
            $this->assertSame('unknown_permission', $r['json']['code'], 'die Wildcard ist kein Recht');
            $r = $this->act($action, null);
            $this->assertSame(400, $r['status']);
            $this->assertSame('permission_required', $r['json']['code']);
        }
        $this->assertSame([], $this->overridesOf(self::TARGET));
        $this->assertSame([], $this->app->auditLog());
    }

    public function testUnbekanntesKontoKommt404(): void
    {
        $this->asAdmin();

        $r = $this->grant('accounts.view', self::ADMIN, '99999999-9999-4999-8999-999999999999');

        $this->assertSame(404, $r['status']);
        $this->assertSame('account_not_found', $r['json']['code']);
    }

    // ---- Lesesicht -------------------------------------------------------------------------

    /** @return array<string,array<string,mixed>> Aktionen der Lesesicht nach Recht */
    private function actionsByPermission(array $response): array
    {
        return array_column($response['json']['permission_actions'], null, 'permission');
    }

    public function testDetailNenntFuerJedesKatalogrechtDieMoeglicheEinzelrechtAktionMitBegruendung(): void
    {
        $this->target();
        $this->verwalter('permissions.assign', 'accounts.view');
        $this->customRole('a', ['accounts.view']);
        $this->app->assignRole(self::TARGET, 'r_t36_a');
        $this->app->grantPermission(self::TARGET, 'roles.assign');
        $this->app->pdo()->prepare("INSERT INTO `permission_user_overrides` (`user_uuid`, `permission`, `mode`) VALUES (?, 'roles.manage', 'removed')")->execute([self::TARGET]);

        $r = $this->call('GET', '/api/accounts/' . self::TARGET . '/access', self::VERWALTER);

        $this->assertSame(200, $r['status']);
        $actions = $this->actionsByPermission($r);
        $this->assertSame(['accounts.view', 'accounts.invite', 'accounts.deactivate', 'accounts.revoke_sessions', 'accounts.reset_mfa', 'roles.manage', 'roles.assign', 'permissions.assign', 'school_years.view', 'school_years.manage', 'classes.view', 'classes.manage', 'subjects.view', 'subjects.manage', 'school_settings.manage', 'teaching_groups.view', 'teaching_groups.manage', 'teacher_assignments.manage'], array_column($r['json']['permission_actions'], 'permission'), 'Katalogreihenfolge, eine Aktion je Recht');
        $this->assertSame('Konten ansehen', $actions['accounts.view']['name']);
        $this->assertSame('Konten', $actions['accounts.view']['group']);
        $this->assertSame(['action' => 'withdraw', 'allowed' => true, 'code' => null, 'reason' => null], array_intersect_key($actions['accounts.view'], array_flip(['action', 'allowed', 'code', 'reason'])), 'Recht aus Rolle: entziehen');
        $this->assertSame('grant', $actions['accounts.invite']['action'], 'ohne Herkunft: gewaehren');
        $this->assertFalse($actions['accounts.invite']['allowed']);
        $this->assertSame('permission_not_held', $actions['accounts.invite']['code']);
        $this->assertNotEmpty($actions['accounts.invite']['reason']);
        $this->assertSame('clear', $actions['roles.assign']['action'], 'gewaehrtes Einzelrecht: zuruecksetzen');
        $this->assertSame('permission_not_held', $actions['roles.assign']['code']);
        $this->assertSame('clear', $actions['roles.manage']['action'], 'entzogenes Einzelrecht: zuruecksetzen');
        $this->assertFalse($actions['roles.manage']['allowed']);
        $this->assertSame('grant', $actions['permissions.assign']['action']);
        $this->assertSame('permissions.assign', $actions['permissions.assign']['permission']);
    }

    public function testDetailSperrtEinzelrechteBeiSchuelerAdminUndSelbstMitBegruendung(): void
    {
        $this->asAdmin();
        $this->verwalter('permissions.assign', 'accounts.view');
        $this->target();
        $this->app->assignRole(self::TARGET, 'schueler');

        $schueler = $this->actionsByPermission($this->call('GET', '/api/accounts/' . self::TARGET . '/access', self::ADMIN));
        $this->assertFalse($schueler['accounts.view']['allowed']);
        $this->assertSame('student_role_exclusive', $schueler['accounts.view']['code']);

        $admin = $this->actionsByPermission($this->call('GET', '/api/accounts/' . self::ADMIN . '/access', self::VERWALTER));
        $this->assertSame('permission_not_held', $admin['roles.manage']['code'], 'P2 vor Schuladmin-Regel');
        $adminVonAdmin = $this->actionsByPermission($this->call('GET', '/api/accounts/' . self::ADMIN . '/access', self::ADMIN));
        $this->assertSame('withdraw', $adminVonAdmin['accounts.view']['action']);
        $this->assertSame('admin_overrides_forbidden', $adminVonAdmin['accounts.view']['code']);

        $self = $this->actionsByPermission($this->call('GET', '/api/accounts/' . self::VERWALTER . '/access', self::VERWALTER));
        $this->assertSame('self_escalation', $self['permissions.assign']['code']);
        $this->assertSame('clear', $self['permissions.assign']['action']);
    }
}
