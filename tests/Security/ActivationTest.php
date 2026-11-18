<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Security;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/** Aktivierung bei der ersten Anmeldung (Ticket #25, ADR 0002 B1): ein gueltiges Token hebt `invited` auf `active`, `inactive` bleibt gesperrt. */
final class ActivationTest extends TestCase
{
    private const SUB = '22222222-2222-4222-8222-222222222222';

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

    public function testZweiteAnmeldungSchreibtKeinenZweitenAktivierungsEintrag(): void
    {
        $this->app->createAccount(self::SUB, 'invited', 'a@schule.test');

        $this->assertSame(200, $this->me()['status']);
        $this->assertSame(200, $this->me()['status']);

        $this->assertSame(['account.activated'], array_column($this->app->auditLog(), 'action'));
    }

    public function testInaktivesKontoBleibtGesperrtUndWirdNichtHochgestuft(): void
    {
        $this->app->createAccount(self::SUB, 'inactive', 'a@schule.test');

        $r = $this->me();

        $this->assertSame(401, $r['status']);
        $this->assertSame('account_inactive', $r['json']['code']);
        $this->assertSame('inactive', $this->app->accounts()[0]['status']);
        $this->assertSame([], $this->app->auditLog());
    }
}
