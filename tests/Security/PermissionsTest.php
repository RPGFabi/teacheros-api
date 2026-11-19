<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Security;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Rechte-Grundlage (Ticket #24): Rolle Schuladmin, Rechte-Katalog und die
 * effektiven Rechte in GET /api/me. Alles ueber HTTP gegen php-cgi + MariaDB.
 */
final class PermissionsTest extends TestCase
{
    private const SUB = '11111111-1111-4111-8111-111111111111';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
    }

    private function me(): array
    {
        return $this->app->request('GET', '/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::SUB)]);
    }

    public function testSchuladminBekommtInMeDenGanzenKatalogOhneWildcard(): void
    {
        $this->app->createAccount(self::SUB, 'active');
        $this->app->assignRole(self::SUB, 'schuladmin');

        $r = $this->me();

        $this->assertSame(200, $r['status']);
        $this->assertEqualsCanonicalizing(['accounts.view', 'accounts.invite', 'accounts.deactivate', 'accounts.revoke_sessions', 'roles.manage', 'roles.assign', 'permissions.assign'], $r['json']['permissions']);
        $this->assertNotContains('*', $r['json']['permissions']);
    }

    public function testKontoOhneRolleHatLeereRechteListe(): void
    {
        $this->app->createAccount(self::SUB, 'active');

        $r = $this->me();

        $this->assertSame(200, $r['status']);
        $this->assertSame([], $r['json']['permissions']);
    }

    public function testWiederholteMigrationLegtSchuladminNichtDoppeltAnUndBehaeltZuweisungen(): void
    {
        $this->app->createAccount(self::SUB, 'active');
        $this->app->assignRole(self::SUB, 'schuladmin');

        $this->app->rerunMigrations('0003_permissions.php', '0004_schuladmin_role.php');

        $this->assertSame(1, (int) $this->app->pdo()->query("SELECT COUNT(*) FROM `permission_roles` WHERE `role_key` = 'schuladmin'")->fetchColumn());
        $this->assertSame(['*'], $this->app->pdo()->query("SELECT p.`permission` FROM `permission_role_permissions` p JOIN `permission_roles` r ON r.`id` = p.`role_id` WHERE r.`role_key` = 'schuladmin'")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertEqualsCanonicalizing(['accounts.view', 'accounts.invite', 'accounts.deactivate', 'accounts.revoke_sessions', 'roles.manage', 'roles.assign', 'permissions.assign'], $this->me()['json']['permissions']);
    }
}
