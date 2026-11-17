<?php

declare(strict_types=1);

namespace TeacherOS\Tests;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;

final class HealthEndpointTest extends TestCase
{
    public function testHealthMeldetOkMitErreichbarerDatenbank(): void
    {
        $r = HttpTestApp::boot()->request('GET', '/api/health');

        $this->assertSame(200, $r['status']);
        $this->assertSame('ok', $r['json']['status']);
        $this->assertSame('teacheros-api', $r['json']['service']);
        $this->assertNotFalse(strtotime($r['json']['timestamp']));
        $this->assertSame('ok', $r['json']['database']);
    }

    public function testHealthAntwortetMit503OhneDatenbank(): void
    {
        $r = HttpTestApp::boot()->request('GET', '/api/health', ['DB_PORT' => '1']);

        $this->assertSame(503, $r['status']);
        $this->assertSame('error', $r['json']['status']);
        $this->assertSame('unavailable', $r['json']['database']);
    }

    public function testMigrationenLaufenGenauEinmal(): void
    {
        $app = HttpTestApp::boot();
        $e = $app->env();
        $pdo = new \PDO("mysql:host={$e['DB_HOST']};port={$e['DB_PORT']};dbname={$app->dbName}", $e['DB_USER'], $e['DB_PASSWORD']);
        $count = fn () => (int) $pdo->query("SELECT COUNT(*) FROM _setup_migrations WHERE mode='migrate'")->fetchColumn();
        $before = $count();

        $app->migrate();

        $this->assertGreaterThanOrEqual(1, $before);
        $this->assertSame($before, $count());
    }
}
