<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Modules;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Modulfreigabe vor Rechten (ADR 0002 B5, Ticket #13). Die Probe-Route `/api/test/observe-probe` steht fuer eine
 * Fachroute des Moduls `observe` und verlangt `accounts.view` (tests/Support/module-probe-routes.php).
 */
final class ModuleGateTest extends TestCase
{
    private const SUB = '13131313-1313-4131-8131-131313131313';
    private const PROBE = '/api/test/observe-probe';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->resetModules();
        $this->app->createAccount(self::SUB, 'active');
    }

    private function get(string $uri): array
    {
        return $this->app->request('GET', $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::SUB)]);
    }

    private static function utc(string $modify): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($modify)->format('Y-m-d H:i:s.u');
    }

    public function testInaktivesModulLiefertModuleDisabledAuchMitAllenRechten(): void
    {
        $this->app->assignRole(self::SUB, 'schuladmin');

        $r = $this->get(self::PROBE);

        $this->assertSame(403, $r['status']);
        $this->assertSame('module_disabled', $r['json']['code']);
        $this->assertSame('observe', $r['json']['module']);
    }

    public function testModulpruefungLaeuftVorDerRechtepruefung(): void
    {
        // Ohne Recht und ohne Freigabe: der Grund ist das Modul, nicht das fehlende Recht.
        $r = $this->get(self::PROBE);

        $this->assertSame(403, $r['status']);
        $this->assertSame('module_disabled', $r['json']['code']);
    }

    public function testAktivesModulOhneRechtBleibtVerboten(): void
    {
        $this->app->insertEntitlement('observe', true, self::utc('-1 hour'));

        $r = $this->get(self::PROBE);

        $this->assertSame(403, $r['status']);
        $this->assertSame('forbidden', $r['json']['code'], 'eine Freigabe gewaehrt kein Recht');
    }

    public function testAktivesModulMitRechtLaesstDurch(): void
    {
        $this->app->insertEntitlement('observe', true, self::utc('-1 hour'));
        $this->app->grantPermission(self::SUB, 'accounts.view');

        $r = $this->get(self::PROBE);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertTrue($r['json']['ok']);
    }

    public function testOhneAnmeldungZuerst401NichtModuleDisabled(): void
    {
        $r = $this->app->request('GET', self::PROBE);

        $this->assertSame(401, $r['status']);
    }

    public function testGiltDieNeuesteVersionDerenBeginnErreichtIst(): void
    {
        $this->app->grantPermission(self::SUB, 'accounts.view');
        $this->app->insertEntitlement('observe', true, self::utc('-2 days'));
        $this->app->insertEntitlement('observe', false, self::utc('-1 hour'));

        $this->assertSame('module_disabled', $this->get(self::PROBE)['json']['code'], 'die spaetere Ruecknahme gilt');

        $this->app->insertEntitlement('observe', true, self::utc('+1 day'));

        $this->assertSame('module_disabled', $this->get(self::PROBE)['json']['code'], 'eine kuenftige Freigabe gilt noch nicht');
    }

    public function testMeNenntAktiveModuleImmerMitCore(): void
    {
        $this->assertSame(['core'], $this->get('/api/me')['json']['modules']);

        $this->app->insertEntitlement('grade', true, self::utc('-1 hour'));
        $this->app->insertEntitlement('observe', true, self::utc('-1 hour'));
        $this->app->insertEntitlement('class', true, self::utc('+1 day'));

        $this->assertSame(['core', 'observe', 'grade'], $this->get('/api/me')['json']['modules'], 'Katalogreihenfolge, ohne kuenftige Freigabe');
    }

    public function testCoreRoutenBrauchenKeineFreigabe(): void
    {
        $this->app->grantPermission(self::SUB, 'accounts.view');

        $this->assertSame(200, $this->get('/api/accounts')['status']);
    }
}
