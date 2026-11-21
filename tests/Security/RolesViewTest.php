<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Rollen ansehen (Ticket #32): Rechte-Katalog (GET /api/permissions) und Rollenliste (GET /api/roles)
 * ueber HTTP gegen php-cgi + Test-DB; die Standardrollen legt das Setup an (Migrationen der Testdatenbank).
 */
final class RolesViewTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
    }

    private function call(string $uri, string $sub = self::ADMIN): array
    {
        return $this->app->request('GET', $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)]);
    }

    private function asAdmin(): void
    {
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
    }

    /** @return array<string,array<string,mixed>> Rollen nach Schluessel */
    private function rolesByKey(): array
    {
        $this->asAdmin();
        return array_column($this->call('/api/roles')['json']['roles'], null, 'key');
    }

    public function testKatalogFuehrtJedesRechtMitDeutschemNamenUndGruppe(): void
    {
        $this->asAdmin();

        $r = $this->call('/api/permissions');

        $this->assertSame(200, $r['status']);
        $byKey = array_column($r['json']['permissions'], null, 'key');
        $this->assertEqualsCanonicalizing(
            ['accounts.view', 'accounts.invite', 'accounts.deactivate', 'accounts.revoke_sessions', 'accounts.reset_mfa', 'roles.manage', 'roles.assign', 'permissions.assign', 'school_years.view', 'school_years.manage', 'classes.view', 'classes.manage', 'subjects.view', 'subjects.manage', 'school_settings.manage'],
            array_keys($byKey),
        );
        $this->assertSame(['key' => 'roles.manage', 'name' => 'Rollen verwalten', 'group' => 'Rollen und Rechte'], $byKey['roles.manage']);
        $this->assertSame('Rollen vergeben und entziehen', $byKey['roles.assign']['name']);
        $this->assertSame('Einzelrechte vergeben und entziehen', $byKey['permissions.assign']['name']);
        $this->assertSame('Konten', $byKey['accounts.view']['group']);
        foreach ($byKey as $entry) {
            $this->assertNotSame('', $entry['name']);
            $this->assertNotSame('', $entry['group']);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function verwaltungsrechte(): iterable
    {
        yield 'roles.manage' => ['roles.manage'];
        yield 'roles.assign' => ['roles.assign'];
        yield 'permissions.assign' => ['permissions.assign'];
    }

    #[DataProvider('verwaltungsrechte')]
    public function testBeideEndpunkteSindMitJedemDerDreiVerwaltungsrechteLesbar(string $permission): void
    {
        $this->app->createAccount(self::OTHER, 'active');
        $this->app->grantPermission(self::OTHER, $permission);

        $this->assertSame(200, $this->call('/api/permissions', self::OTHER)['status']);
        $this->assertSame(200, $this->call('/api/roles', self::OTHER)['status']);
    }

    public function testOhneVerwaltungsrechtKommt403ForbiddenAuchMitKontenrechten(): void
    {
        $this->app->createAccount(self::OTHER, 'active');
        $this->app->grantPermission(self::OTHER, 'accounts.view');
        $this->app->grantPermission(self::OTHER, 'accounts.invite');

        foreach (['/api/permissions', '/api/roles'] as $uri) {
            $r = $this->call($uri, self::OTHER);
            $this->assertSame(403, $r['status'], $uri);
            $this->assertSame('forbidden', $r['json']['code'], $uri);
        }
    }

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('GET', '/api/roles')['status']);
        $this->assertSame(401, $this->app->request('GET', '/api/permissions')['status']);
    }

    public function testJedeSchulinstanzHatDieSechsStandardrollenMitFestemMfaMerkmalUndSchutzstufe(): void
    {
        $roles = $this->rolesByKey();

        $expected = [
            'schuladmin' => ['Schuladmin', true, 'wildcard'],
            'schueler' => ['Schüler/in', false, 'protected'],
            'lehrkraft' => ['Lehrkraft', true, 'standard'],
            'erziehungsberechtigte' => ['Erziehungsberechtigte/r', false, 'standard'],
            'sekretariat' => ['Sekretariat', true, 'standard'],
            'schulleitung' => ['Schulleitung', true, 'standard'],
        ];
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($roles));
        foreach ($expected as $key => [$name, $mfa, $protection]) {
            $this->assertSame($name, $roles[$key]['name'], $key);
            $this->assertSame($mfa, $roles[$key]['requires_mfa'], $key);
            $this->assertSame($protection, $roles[$key]['protection'], $key);
            $this->assertNotEmpty($roles[$key]['id'], $key);
            $this->assertNotSame('', (string) $roles[$key]['description'], $key);
        }
    }

    public function testStandardrollenAusserSchuladminStartenOhneRechte(): void
    {
        $roles = $this->rolesByKey();

        foreach (['schueler', 'lehrkraft', 'erziehungsberechtigte', 'sekretariat', 'schulleitung'] as $key) {
            $this->assertSame([], $roles[$key]['permissions'], $key);
            $this->assertFalse($roles[$key]['all_permissions'], $key);
        }
    }

    public function testWildcardRolleErscheintAlsAlleRechteOhneSternchen(): void
    {
        $roles = $this->rolesByKey();

        $this->assertTrue($roles['schuladmin']['all_permissions']);
        $this->assertEqualsCanonicalizing(
            ['accounts.view', 'accounts.invite', 'accounts.deactivate', 'accounts.revoke_sessions', 'accounts.reset_mfa', 'roles.manage', 'roles.assign', 'permissions.assign', 'school_years.view', 'school_years.manage', 'classes.view', 'classes.manage', 'subjects.view', 'subjects.manage', 'school_settings.manage'],
            $roles['schuladmin']['permissions'],
        );
        $this->assertStringNotContainsString('"*"', $this->call('/api/roles')['body']);
    }

    public function testRollenzeigenAnzahlDerKontenUndRechteAusDerRolle(): void
    {
        $this->asAdmin();
        $this->app->createAccount(self::OTHER, 'active');
        $this->app->assignRole(self::OTHER, 'lehrkraft');
        $this->app->pdo()->exec("INSERT INTO `permission_role_permissions` (`role_id`, `permission`) SELECT `id`, 'accounts.view' FROM `permission_roles` WHERE `role_key` = 'lehrkraft'");

        $roles = array_column($this->call('/api/roles')['json']['roles'], null, 'key');

        $this->assertSame(1, $roles['schuladmin']['account_count']);
        $this->assertSame(1, $roles['lehrkraft']['account_count']);
        $this->assertSame(0, $roles['sekretariat']['account_count']);
        $this->assertSame(['accounts.view'], $roles['lehrkraft']['permissions']);
    }

    public function testStandardrollenstehenInFesterReihenfolgeVorSelbstAngelegtenRollen(): void
    {
        $this->asAdmin();
        $this->app->pdo()->exec("INSERT INTO `permission_roles` (`id`, `role_key`, `name`, `is_system`) VALUES ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'r_abc', 'AAA Eigene Rolle', 0)");
        $this->app->pdo()->exec("INSERT INTO `role_settings` (`role_id`, `protection`, `requires_mfa`) VALUES ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'custom', 1)");

        $roles = $this->call('/api/roles')['json']['roles'];

        $this->assertSame(
            ['schuladmin', 'schueler', 'lehrkraft', 'erziehungsberechtigte', 'sekretariat', 'schulleitung', 'r_abc'],
            array_column($roles, 'key'),
        );
        $this->assertSame('custom', $roles[6]['protection']);
        $this->assertTrue($roles[6]['requires_mfa']);
        $this->app->pdo()->exec("DELETE FROM `permission_roles` WHERE `role_key` = 'r_abc'");
    }
}
