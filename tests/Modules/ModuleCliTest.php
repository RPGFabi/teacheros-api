<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Modules;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Modulfreigaben setzen (Ticket #13): bin/module.php als Unterprozess gegen die Test-DB. Jede Aenderung ist eine neue
 * Version mit Gueltigkeitsbeginn und Audit-Eintrag; `core` ist nicht abschaltbar.
 */
final class ModuleCliTest extends TestCase
{
    private const SUB = '13131313-1313-4131-8131-000000000013';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->resetModules();
        $this->app->createAccount(self::SUB, 'active');
    }

    /** @return array{code:int, out:string, err:string} */
    private function module(string ...$args): array
    {
        return $this->app->cli('bin/module.php', $args);
    }

    /** @return list<string> */
    private function activeModules(): array
    {
        return $this->app->request('GET', '/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::SUB)])['json']['modules'];
    }

    public function testFreigebenWirktSofortUndWirdAuditiert(): void
    {
        $r = $this->module('enable', 'observe');

        $this->assertSame(0, $r['code'], $r['out'] . $r['err']);
        $this->assertSame(['core', 'observe'], $this->activeModules());

        $audit = $this->app->auditLog();
        $this->assertCount(1, $audit);
        $this->assertSame('module.enabled', $audit[0]['action']);
        $this->assertSame('cli', $audit[0]['actor_type']);
        $this->assertSame('module', $audit[0]['object_type']);
        $this->assertSame('observe', $audit[0]['object_id']);
        $this->assertArrayHasKey('valid_from', json_decode($audit[0]['context'], true));
    }

    public function testRuecknahmeIstNeueVersionDieAlteBleibtErhalten(): void
    {
        $this->module('enable', 'observe');
        $r = $this->module('disable', 'observe');

        $this->assertSame(0, $r['code'], $r['out'] . $r['err']);
        $this->assertSame(['core'], $this->activeModules());
        $this->assertSame(['1', '0'], array_map('strval', array_column($this->app->moduleEntitlements(), 'enabled')), 'Historie: beide Versionen');
        $this->assertSame(['module.enabled', 'module.disabled'], array_column($this->app->auditLog(), 'action'));
    }

    public function testFreigabeMitKuenftigemBeginnGiltErstDann(): void
    {
        $from = (new \DateTimeImmutable('+2 days'))->format(\DATE_ATOM);

        $r = $this->module('enable', 'grade', '--valid-from=' . $from);

        $this->assertSame(0, $r['code'], $r['out'] . $r['err']);
        $this->assertSame(['core'], $this->activeModules());
        $this->assertCount(1, $this->app->moduleEntitlements());
    }

    public function testCoreIstNichtAbschaltbar(): void
    {
        $r = $this->module('disable', 'core');

        $this->assertSame(3, $r['code']);
        $this->assertStringContainsString('core_always_active', $r['err']);
        $this->assertSame([], $this->app->moduleEntitlements());
        $this->assertSame([], $this->app->auditLog());
        $this->assertSame(['core'], $this->activeModules());
    }

    public function testUnbekanntesModulWirdAbgelehnt(): void
    {
        $r = $this->module('enable', 'inventar');

        $this->assertSame(3, $r['code']);
        $this->assertStringContainsString('unknown_module', $r['err']);
        $this->assertSame([], $this->app->moduleEntitlements());
    }

    public function testBeginnInDerVergangenheitWirdAbgelehnt(): void
    {
        $r = $this->module('enable', 'observe', '--valid-from=2020-01-01T00:00:00Z');

        $this->assertSame(3, $r['code']);
        $this->assertStringContainsString('valid_from_in_past', $r['err']);
        $this->assertSame([], $this->app->moduleEntitlements());
    }

    public function testOhneAenderungKeineNeueVersion(): void
    {
        $this->module('enable', 'observe');

        $r = $this->module('enable', 'observe');

        $this->assertSame(3, $r['code']);
        $this->assertStringContainsString('already_enabled', $r['err']);
        $this->assertCount(1, $this->app->moduleEntitlements());
        $this->assertStringContainsString('already_disabled', $this->module('disable', 'grade')['err']);
    }

    public function testListeUndHistorie(): void
    {
        $this->module('enable', 'observe');
        $this->module('disable', 'observe');

        $list = $this->module('list');
        $this->assertSame(0, $list['code']);
        $this->assertMatchesRegularExpression('/^core\s+aktiv/m', $list['out']);
        $this->assertMatchesRegularExpression('/^observe\s+inaktiv/m', $list['out']);

        $history = $this->module('history', 'observe');
        $this->assertSame(0, $history['code']);
        $this->assertSame(2, substr_count($history['out'], "\n"));
        $this->assertStringContainsString('nicht freigegeben', $history['out']);
    }

    public function testFalscherAufruf(): void
    {
        $this->assertSame(2, $this->module()['code']);
        $this->assertSame(2, $this->module('enable')['code']);
        $this->assertSame(2, $this->module('enable', 'observe', '--valid-from=kein-datum')['code']);
    }
}
