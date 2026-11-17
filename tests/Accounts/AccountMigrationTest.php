<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Accounts;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;

/** Migrationen 0005 (Einladungsdaten am Konto) und 0006 (Audit) sind wiederholbar und behalten Daten. */
final class AccountMigrationTest extends TestCase
{
    public function testWiederholteMigrationBehaeltKontenUndAuditEintraege(): void
    {
        $app = HttpTestApp::boot();
        $app->resetAccounts();
        $app->createAccount('88888888-8888-4888-8888-888888888888', 'invited', 'a@schule.test', 'frau.a');
        $app->pdo()->exec("INSERT INTO `audit_log` (`actor_type`, `action`, `object_type`, `object_id`) VALUES ('cli', 'account.invited', 'account', 'x')");

        $app->rerunMigrations('0005_account_invitation_data.php', '0006_audit_log.php');

        $accounts = $app->accounts();
        $this->assertCount(1, $accounts);
        $this->assertSame('frau.a', $accounts[0]['login_name']);
        $this->assertSame('a@schule.test', $accounts[0]['email']);
        $this->assertCount(1, $app->auditLog());
    }

    public function testEmailUndAnmeldenameSindEindeutigAuchOhneBeachtungDerGrossschreibung(): void
    {
        $app = HttpTestApp::boot();
        $app->resetAccounts();
        $app->createAccount('88888888-8888-4888-8888-888888888888', 'invited', 'a@schule.test', 'frau.a');

        $this->expectException(\PDOException::class);
        $app->createAccount('99999999-9999-4999-8999-999999999999', 'invited', 'A@Schule.test', 'andere');
    }
}
