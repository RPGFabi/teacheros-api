<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Accounts;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\AuthStub;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * "Abgleich offen" als Menge offener Aufrufe je Konto (Ticket #47, ADR 0002 Nachtrag #16): jeder offene Aufruf an den
 * Anmeldedienst hat einen eigenen Ablehnungscode, die Lesesicht nennt die Menge, "Abgleich wiederholen" holt alle in
 * fester Reihenfolge nach. Der HTTP-Teil prueft die Lesesicht der Sperre (der einzige Aufruf, den es heute gibt). Der
 * Dienst-Teil ({@see self::resyncWith()}) setzt ueber `tests/Support/open-calls.php` eigene Aufrufe ein und belegt damit
 * Menge, Reihenfolge und Ablehnung, wie sie mit Widerruf (#48) und Zuruecksetzung (#49) gelten, ohne dass es sie schon gibt.
 */
final class OpenCallsTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const TARGET = '33333333-3333-4333-8333-333333333333';

    private HttpTestApp $app;
    private AuthStub $stub;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->stub = AuthStub::start();
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        $this->app->createAccount(self::TARGET, 'active', 'ziel@schule.test');
    }

    protected function tearDown(): void
    {
        $this->stub->stop();
    }

    private function as(string $sub, string $method, string $uri): array
    {
        return $this->app->request($method, $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)] + $this->stub->env());
    }

    // ---- Lesesicht ------------------------------------------------------------------------------

    public function testEinKontoOhneOffenenAufrufNenntEineLeereMenge(): void
    {
        $r = $this->as(self::ADMIN, 'GET', '/api/accounts/' . self::TARGET . '/access');

        $this->assertSame(200, $r['status']);
        $this->assertSame([], $r['json']['account']['open_calls']);
    }

    public function testEineAbgelehnteSperreStehtMitIhremCodeInDerMenge(): void
    {
        $this->stub->script('disable-login', ['status' => 404, 'body' => ['error' => 'x', 'code' => 'user_not_found']]);

        $r = $this->as(self::ADMIN, 'POST', '/api/accounts/' . self::TARGET . '/deactivate');

        $this->assertSame([['operation' => 'disable_login', 'rejection_code' => 'user_not_found']], $r['json']['account']['open_calls']);
    }

    public function testNichterreichbarkeitLaesstDenCodeLeer(): void
    {
        $this->stub->script('disable-login', ['status' => 503, 'body' => []]);

        $r = $this->as(self::ADMIN, 'POST', '/api/accounts/' . self::TARGET . '/deactivate');

        $this->assertSame([['operation' => 'disable_login', 'rejection_code' => null]], $r['json']['account']['open_calls']);
    }

    public function testDerErfolgreicheAbgleichLeertDieMenge(): void
    {
        $this->stub->script('disable-login', ['status' => 503, 'body' => []]);
        $this->as(self::ADMIN, 'POST', '/api/accounts/' . self::TARGET . '/deactivate');
        $this->stub->script('disable-login', ['status' => 200, 'body' => ['ok' => true]]);

        $r = $this->as(self::ADMIN, 'POST', '/api/accounts/' . self::TARGET . '/resync-login');

        $this->assertSame(200, $r['status']);
        $this->assertSame([], $r['json']['account']['open_calls']);
    }

    public function testDieLesesichtNenntDieMengeInFesterReihenfolgeUnbekannteZuletzt(): void
    {
        $this->app->setAccountStatus(self::TARGET, 'inactive');
        $this->app->addOpenCall(self::TARGET, 'zukuenftiger_aufruf', 'x');
        $this->app->addOpenCall(self::TARGET, 'disable_login');

        $r = $this->as(self::ADMIN, 'GET', '/api/accounts/' . self::TARGET . '/access');

        $this->assertSame(['disable_login', 'zukuenftiger_aufruf'], array_column($r['json']['account']['open_calls'], 'operation'));
    }

    public function testDieReaktivierungNimmtNurDieSperreAusDerMenge(): void
    {
        $this->stub->script('disable-login', ['status' => 503, 'body' => []]);
        $this->as(self::ADMIN, 'POST', '/api/accounts/' . self::TARGET . '/deactivate');
        $this->app->addOpenCall(self::TARGET, 'reset_mfa', 'x');

        $r = $this->as(self::ADMIN, 'POST', '/api/accounts/' . self::TARGET . '/reactivate');

        $this->assertSame(200, $r['status']);
        $this->assertSame([['operation' => 'reset_mfa', 'rejection_code' => 'x']], $r['json']['account']['open_calls']);
    }

    // ---- Menge, Reihenfolge, Ablehnung (eigene Aufrufe) -----------------------------------------

    /**
     * Holt die offenen Aufrufe von $accountId mit Handlern nach, die in der Reihenfolge von $handlers laufen.
     *
     * @param list<array{op:string,outcome:string}> $handlers
     * @return array{calls:list<string>,thrown:?array{code:string,status:int}}
     */
    private function resyncWith(array $handlers, string $accountId = self::TARGET): array
    {
        $r = $this->app->cli('tests/Support/open-calls.php', [$accountId, json_encode($handlers, JSON_THROW_ON_ERROR)]);
        $this->assertSame('', $r['err']);
        return json_decode($r['out'], true, flags: JSON_THROW_ON_ERROR);
    }

    public function testEinKontoTraegtMehrereOffeneAufrufeMitEigenemCode(): void
    {
        $this->app->addOpenCall(self::TARGET, 'a', 'code_a');
        $this->app->addOpenCall(self::TARGET, 'b', null);

        $this->assertSame(['a' => 'code_a', 'b' => null], $this->app->openCalls(self::TARGET));
    }

    public function testAlleOffenenAufrufeLaufenInDerReihenfolgeDerHandlerNichtDerEintragung(): void
    {
        foreach (['c', 'a', 'b'] as $op) {
            $this->app->addOpenCall(self::TARGET, $op);
        }

        $result = $this->resyncWith([['op' => 'a', 'outcome' => 'ok'], ['op' => 'b', 'outcome' => 'ok'], ['op' => 'c', 'outcome' => 'ok']]);

        $this->assertSame(['a', 'b', 'c'], $result['calls']);
        $this->assertNull($result['thrown']);
        $this->assertSame([], $this->app->openCalls(self::TARGET), 'jeder erfolgreiche Aufruf verlaesst die Menge');
    }

    public function testEineAblehnungBehaeltIhrenCodeUndHindertDieFolgendenNicht(): void
    {
        foreach (['a', 'b', 'c'] as $op) {
            $this->app->addOpenCall(self::TARGET, $op);
        }

        $result = $this->resyncWith([['op' => 'a', 'outcome' => 'ok'], ['op' => 'b', 'outcome' => 'reject:user_not_found:404'], ['op' => 'c', 'outcome' => 'ok']]);

        $this->assertSame(['a', 'b', 'c'], $result['calls']);
        $this->assertSame(['code' => 'user_not_found', 'status' => 404], $result['thrown']);
        $this->assertSame(['b' => 'user_not_found'], $this->app->openCalls(self::TARGET));
    }

    public function testZweiAbgelehnteAufrufeBehaltenJeIhrenCodeUndGeworfenWirdDerErste(): void
    {
        $this->app->addOpenCall(self::TARGET, 'a');
        $this->app->addOpenCall(self::TARGET, 'b');

        $result = $this->resyncWith([['op' => 'a', 'outcome' => 'reject:code_a:409'], ['op' => 'b', 'outcome' => 'reject:code_b:404']]);

        $this->assertSame(['code' => 'code_a', 'status' => 409], $result['thrown']);
        $this->assertSame(['a' => 'code_a', 'b' => 'code_b'], $this->app->openCalls(self::TARGET));
    }

    public function testEinErfolgLeertDenAltenCode(): void
    {
        $this->app->addOpenCall(self::TARGET, 'a', 'alter_code');

        $result = $this->resyncWith([['op' => 'a', 'outcome' => 'ok']]);

        $this->assertNull($result['thrown']);
        $this->assertSame([], $this->app->openCalls(self::TARGET));
    }

    public function testNichterreichbarkeitBeendetDenLaufOhneCodeZuSetzen(): void
    {
        foreach (['a', 'b', 'c'] as $op) {
            $this->app->addOpenCall(self::TARGET, $op, $op === 'b' ? 'alter_code' : null);
        }

        $result = $this->resyncWith([['op' => 'a', 'outcome' => 'ok'], ['op' => 'b', 'outcome' => 'unavailable'], ['op' => 'c', 'outcome' => 'ok']]);

        $this->assertSame(['a', 'b'], $result['calls'], 'der folgende Aufruf wartet nicht auf dieselbe Zeitueberschreitung');
        $this->assertSame(['code' => 'auth_unavailable', 'status' => 502], $result['thrown']);
        $this->assertSame(['b' => 'alter_code', 'c' => null], $this->app->openCalls(self::TARGET));
    }

    public function testEinHinfaelligerAufrufVerlaesstDieMengeOhneAufruf(): void
    {
        $this->app->addOpenCall(self::TARGET, 'a');
        $this->app->addOpenCall(self::TARGET, 'b');

        $result = $this->resyncWith([['op' => 'a', 'outcome' => 'obsolete'], ['op' => 'b', 'outcome' => 'ok']]);

        $this->assertSame(['b'], $result['calls']);
        $this->assertNull($result['thrown']);
        $this->assertSame([], $this->app->openCalls(self::TARGET));
    }

    public function testNurHinfaelligeAufrufeSindKeinOffenerAbgleich(): void
    {
        $this->app->addOpenCall(self::TARGET, 'a');

        $result = $this->resyncWith([['op' => 'a', 'outcome' => 'obsolete']]);

        $this->assertSame([], $result['calls']);
        $this->assertSame(['code' => 'sync_not_pending', 'status' => 409], $result['thrown']);
        $this->assertSame([], $this->app->openCalls(self::TARGET));
    }

    public function testOhneOffenenAufrufIstNichtsAbzugleichen(): void
    {
        $result = $this->resyncWith([['op' => 'a', 'outcome' => 'ok']]);

        $this->assertSame(['code' => 'sync_not_pending', 'status' => 409], $result['thrown']);
    }

    public function testEinAufrufOhneHandlerBleibtUnberuehrt(): void
    {
        $this->app->addOpenCall(self::TARGET, 'a');
        $this->app->addOpenCall(self::TARGET, 'unbekannt');

        $result = $this->resyncWith([['op' => 'a', 'outcome' => 'ok']]);

        $this->assertSame(['a'], $result['calls']);
        $this->assertSame(['unbekannt' => null], $this->app->openCalls(self::TARGET));
    }

    public function testDasNachholenSchreibtKeinenAuditEintrag(): void
    {
        $this->app->addOpenCall(self::TARGET, 'a');

        $this->resyncWith([['op' => 'a', 'outcome' => 'reject:x:409']]);
        $this->resyncWith([['op' => 'a', 'outcome' => 'ok']]);

        $this->assertSame([], $this->app->auditLog());
    }

    public function testEinAufrufIstJeKontoHoechstensEinmalOffen(): void
    {
        $this->app->addOpenCall(self::TARGET, 'a');

        $this->expectException(\PDOException::class);
        $this->app->addOpenCall(self::TARGET, 'a');
    }

    public function testDasLoeschenDesKontosNimmtSeineOffenenAufrufeMit(): void
    {
        $this->app->addOpenCall(self::TARGET, 'a');

        $this->app->pdo()->prepare('DELETE FROM `accounts` WHERE `public_user_id` = ?')->execute([self::TARGET]);

        $this->assertSame([], $this->app->openCalls(self::TARGET));
    }
}
