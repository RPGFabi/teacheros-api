<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Security;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Aktionen auf der Rollenseite (Review #11): GET /api/roles meldet je Rolle, was der Betrachter tun darf
 * (bearbeiten, loeschen, MFA aendern, ein Recht hinzufuegen) samt Code und Begruendung, und fuer eine neue
 * Rolle, welche Rechte er vergeben darf. Dieselben Regeln pruefen die Schreibwege (RoleManagementTest).
 */
final class RoleActionsTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    /** roles.manage + accounts.view (kein Wildcard) */
    private const MANAGER = '22222222-2222-4222-8222-222222222222';
    /** nur roles.assign */
    private const ASSIGNER = '33333333-3333-4333-8333-333333333333';
    private const MEMBER = '44444444-4444-4444-8444-444444444444';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'roles.manage');
        $this->app->grantPermission(self::MANAGER, 'accounts.view');
        $this->app->createAccount(self::ASSIGNER, 'active');
        $this->app->grantPermission(self::ASSIGNER, 'roles.assign');
    }

    protected function tearDown(): void
    {
        $this->app->pdo()->exec("DELETE FROM `permission_roles` WHERE `role_key` LIKE 'r\\_%'");
    }

    /** @return array{status:int, body:string, json:?array} */
    private function get(string $sub = self::ADMIN): array
    {
        return $this->app->request('GET', '/api/roles', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)]);
    }

    /** Legt eine eigene Rolle an (ohne die API); gibt ihre ID zurueck. */
    private function customRole(string $suffix, array $permissions = []): string
    {
        $id = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
        $pdo = $this->app->pdo();
        $pdo->prepare('INSERT INTO `permission_roles` (`id`, `role_key`, `name`, `is_system`) VALUES (?, ?, ?, 0)')->execute([$id, 'r_t11_' . $suffix, 'Testrolle ' . $suffix]);
        foreach ($permissions as $permission) {
            $pdo->prepare('INSERT INTO `permission_role_permissions` (`role_id`, `permission`) VALUES (?, ?)')->execute([$id, $permission]);
        }
        $pdo->prepare("INSERT INTO `role_settings` (`role_id`, `protection`, `requires_mfa`) VALUES (?, 'custom', 1)")->execute([$id]);
        return $id;
    }

    /** @return array<string,array<string,mixed>> Rollen nach Schluessel */
    private function roles(string $sub = self::ADMIN): array
    {
        $r = $this->get($sub);
        $this->assertSame(200, $r['status'], $r['body']);
        return array_column($r['json']['roles'], null, 'key');
    }

    /** @return array<string,array<string,mixed>> Aktionen einer Rolle nach Aktionsname */
    private function actions(array $role): array
    {
        return array_column($role['actions'], null, 'action');
    }

    /** @return array<string,array<string,mixed>> Rechte-Aktionen nach Recht */
    private function permissionActions(array $holder): array
    {
        return array_column($holder['permission_actions'], null, 'permission');
    }

    /** @param array<string,mixed> $action */
    private function assertAllowed(array $action): void
    {
        $this->assertTrue($action['allowed']);
        $this->assertNull($action['code']);
        $this->assertNull($action['reason']);
    }

    /** @param array<string,mixed> $action */
    private function assertRefused(string $code, array $action): void
    {
        $this->assertFalse($action['allowed']);
        $this->assertSame($code, $action['code']);
        $this->assertNotSame('', (string) $action['reason']);
        $this->assertDoesNotMatchRegularExpression('/\b(du|dich|dir|dein\w*|kannst|besitzt|innehast)\b/i', $action['reason']);
    }

    public function testSchuladminDarfEineEigeneRolleBearbeitenLoeschenUndAlleRechteHinzufuegen(): void
    {
        $this->customRole('a', ['accounts.view']);

        $role = $this->roles()['r_t11_a'];

        $actions = $this->actions($role);
        $this->assertSame(['edit', 'delete', 'set_mfa'], array_keys($actions));
        array_map($this->assertAllowed(...), $actions);
        $permissions = $this->permissionActions($role);
        $this->assertEqualsCanonicalizing(['accounts.invite', 'accounts.deactivate', 'roles.manage', 'roles.assign', 'permissions.assign', 'school_years.view', 'school_years.manage', 'classes.view', 'classes.manage'], array_keys($permissions), 'nur Rechte, die die Rolle noch nicht hat');
        array_map($this->assertAllowed(...), $permissions);
    }

    public function testStandardrolleIstBearbeitbarAberWederLoeschbarNochImMfaMerkmalAenderbar(): void
    {
        $actions = $this->actions($this->roles()['lehrkraft']);

        $this->assertAllowed($actions['edit']);
        $this->assertRefused('role_protected', $actions['delete']);
        $this->assertRefused('role_protected', $actions['set_mfa']);
    }

    public function testGeschuetzteRollenSindNichtBearbeitbarUndKeinRechtLaesstSichHinzufuegen(): void
    {
        foreach (['schuladmin', 'schueler'] as $key) {
            $role = $this->roles()[$key];
            $actions = $this->actions($role);
            foreach (['edit', 'delete', 'set_mfa'] as $name) {
                $this->assertRefused('role_protected', $actions[$name]);
            }
            foreach ($this->permissionActions($role) as $permission) {
                $this->assertRefused('role_protected', $permission);
            }
        }
    }

    public function testZugewieseneEigeneRolleIstNichtLoeschbar(): void
    {
        $this->customRole('b');
        $this->app->createAccount(self::MEMBER, 'active');
        $this->app->assignRole(self::MEMBER, 'r_t11_b');

        $actions = $this->actions($this->roles()['r_t11_b']);

        $this->assertAllowed($actions['edit']);
        $this->assertRefused('role_in_use', $actions['delete']);
    }

    public function testOhneRolesManageIstJedeAktionGesperrtMitForbidden(): void
    {
        $this->customRole('c');

        $r = $this->get(self::ASSIGNER);

        $this->assertSame(200, $r['status']);
        $role = array_column($r['json']['roles'], null, 'key')['r_t11_c'];
        foreach ($role['actions'] as $action) {
            $this->assertRefused('forbidden', $action);
        }
        foreach ($role['permission_actions'] as $action) {
            $this->assertRefused('forbidden', $action);
        }
        $this->assertRefused('forbidden', $r['json']['create']);
        $this->assertSame([], array_filter($r['json']['create']['permission_actions'], static fn (array $a) => $a['allowed']));
    }

    public function testOhneWildcardLassenSichNurSelbstBesesseneRechteHinzufuegen(): void
    {
        $this->customRole('d');

        $role = $this->roles(self::MANAGER)['r_t11_d'];

        $permissions = $this->permissionActions($role);
        $this->assertAllowed($permissions['accounts.view']);
        $this->assertAllowed($permissions['roles.manage']);
        foreach (['accounts.invite', 'roles.assign', 'permissions.assign', 'school_years.view', 'school_years.manage', 'classes.view', 'classes.manage'] as $key) {
            $this->assertRefused('permission_not_held', $permissions[$key]);
        }
    }

    public function testEineSelbstInnegehalteneRolleLaesstSichNichtErweiternAberBleibtBearbeitbar(): void
    {
        $this->customRole('e', ['accounts.view']);
        $this->app->assignRole(self::MANAGER, 'r_t11_e');

        $role = $this->roles(self::MANAGER)['r_t11_e'];

        $this->assertAllowed($this->actions($role)['edit']);
        foreach ($this->permissionActions($role) as $permission) {
            $this->assertRefused('self_escalation', $permission);
        }
    }

    public function testNeueRolleMeldetJeRechtObDerBetrachterEsVergebenDarf(): void
    {
        $admin = $this->get()['json']['create'];
        $this->assertAllowed($admin);
        $this->assertCount(10, $admin['permission_actions']);
        array_map($this->assertAllowed(...), $admin['permission_actions']);

        $manager = $this->get(self::MANAGER)['json']['create'];
        $this->assertAllowed($manager);
        $byKey = $this->permissionActions($manager);
        $this->assertAllowed($byKey['accounts.view']);
        $this->assertAllowed($byKey['roles.manage']);
        $this->assertRefused('permission_not_held', $byKey['accounts.invite']);
    }

    public function testAntwortenDerSchreibwegeEnthaltenDieselbenAktionen(): void
    {
        $r = $this->app->request('POST', '/api/roles', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::ADMIN)], ['name' => 'X', 'permissions' => []]);

        $this->assertSame(201, $r['status'], $r['body']);
        $this->assertSame(['edit', 'delete', 'set_mfa'], array_column($r['json']['role']['actions'], 'action'));
        $this->assertCount(10, $r['json']['role']['permission_actions']);
    }
}
