<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Security;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;

/**
 * Standardrollen im Setup (Ticket #32): idempotent, Audit-Eintrag `role.created` (Akteur `cli`)
 * nur beim tatsaechlichen Anlegen. Die Migrationen laufen im Unterprozess gegen die Test-DB.
 */
final class StandardRolesSetupTest extends TestCase
{
    private const KEYS = ['schuladmin', 'schueler', 'lehrkraft', 'erziehungsberechtigte', 'sekretariat', 'schulleitung'];

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
    }

    /** @return list<array<string,mixed>> */
    private function createdAudit(): array
    {
        return array_values(array_filter($this->app->auditLog(), static fn (array $e) => $e['action'] === 'role.created'));
    }

    private function roleCount(string $key): int
    {
        $stmt = $this->app->pdo()->prepare('SELECT COUNT(*) FROM `permission_roles` WHERE `role_key` = ?');
        $stmt->execute([$key]);
        return (int) $stmt->fetchColumn();
    }

    public function testErneutesSetupLegtNichtsNeuAnUndSchreibtKeinenAuditEintrag(): void
    {
        $this->app->rerunMigrations('0007_role_settings.php', '0008_standard_roles.php');

        foreach (self::KEYS as $key) {
            $this->assertSame(1, $this->roleCount($key), $key);
        }
        $this->assertSame(6, (int) $this->app->pdo()->query("SELECT COUNT(*) FROM `role_settings` WHERE `protection` <> 'custom'")->fetchColumn());
        $this->assertSame([], $this->createdAudit());
    }

    public function testSetupLegtFehlendeStandardrollenAnUndAuditiertNurDieNeuenMitAkteurCli(): void
    {
        $this->app->pdo()->exec("DELETE FROM `permission_roles` WHERE `role_key` IN ('sekretariat', 'schulleitung')");

        $this->app->rerunMigrations('0008_standard_roles.php');

        $created = $this->createdAudit();
        $this->assertCount(2, $created);
        $this->assertEqualsCanonicalizing(['sekretariat', 'schulleitung'], array_map(static fn (array $e) => json_decode($e['context'], true)['role'], $created));
        foreach ($created as $entry) {
            $this->assertSame('cli', $entry['actor_type']);
            $this->assertNull($entry['actor_account_id']);
            $this->assertSame('role', $entry['object_type']);
            $this->assertNotSame('', $entry['object_id']);
        }
        $this->assertSame(1, $this->roleCount('sekretariat'));
    }

    public function testSetupAufLeererSchulinstanzLegtAlleSechsAnUndAuditiertJede(): void
    {
        $keys = "'" . implode("','", self::KEYS) . "'";
        $this->app->pdo()->exec("DELETE FROM `permission_roles` WHERE `role_key` IN ($keys)");

        $this->app->rerunMigrations('0004_schuladmin_role.php', '0008_standard_roles.php');

        $this->assertCount(6, $this->createdAudit());
        $perms = $this->app->pdo()->query("SELECT r.`role_key`, COUNT(p.`permission`) FROM `permission_roles` r LEFT JOIN `permission_role_permissions` p ON p.`role_id` = r.`id` GROUP BY r.`role_key`")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(1, (int) $perms['schuladmin']);
        foreach (['schueler', 'lehrkraft', 'erziehungsberechtigte', 'sekretariat', 'schulleitung'] as $key) {
            $this->assertSame(0, (int) $perms[$key], $key);
        }
    }

    public function testErneutesSetupUeberschreibtAenderungenDerSchuleNicht(): void
    {
        $this->app->pdo()->exec("UPDATE `permission_roles` SET `name` = 'Kollegium' WHERE `role_key` = 'lehrkraft'");
        $this->app->pdo()->exec("INSERT INTO `permission_role_permissions` (`role_id`, `permission`) SELECT `id`, 'accounts.view' FROM `permission_roles` WHERE `role_key` = 'lehrkraft'");

        $this->app->rerunMigrations('0008_standard_roles.php');

        $row = $this->app->pdo()->query("SELECT r.`name`, GROUP_CONCAT(p.`permission`) AS perms FROM `permission_roles` r LEFT JOIN `permission_role_permissions` p ON p.`role_id` = r.`id` WHERE r.`role_key` = 'lehrkraft' GROUP BY r.`id`")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Kollegium', $row['name']);
        $this->assertSame('accounts.view', $row['perms']);
        $this->app->pdo()->exec("UPDATE `permission_roles` SET `name` = 'Lehrkraft' WHERE `role_key` = 'lehrkraft'");
        $this->app->pdo()->exec("DELETE p FROM `permission_role_permissions` p JOIN `permission_roles` r ON r.`id` = p.`role_id` WHERE r.`role_key` = 'lehrkraft'");
    }
}
