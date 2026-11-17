<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Accounts;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\AuthStub;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Konten im Web (Ticket #26): GET /api/accounts, POST /api/accounts/invitations und
 * POST /api/accounts/{id}/reinvite ueber HTTP (php-cgi + Test-DB) gegen den Auth-Stub.
 */
final class AccountsEndpointsTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';

    private HttpTestApp $app;
    private AuthStub $stub;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->stub = AuthStub::start();
    }

    protected function tearDown(): void
    {
        $this->stub->stop();
    }

    private function asAdmin(): void
    {
        $this->app->createAccount(self::ADMIN, 'active', 'admin@schule.test');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
    }

    private function call(string $method, string $uri, ?array $json = null, string $sub = self::ADMIN): array
    {
        return $this->app->request($method, $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)] + $this->stub->env(), $json);
    }

    private function invite(array $body, string $sub = self::ADMIN): array
    {
        return $this->call('POST', '/api/accounts/invitations', $body, $sub);
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        return array_column($this->app->auditLog(), 'action');
    }

    public function testListeNurMitAccountsViewUndOhneRechtKommt403MitCode(): void
    {
        $this->app->createAccount(self::OTHER, 'active', 'x@schule.test');

        $r = $this->call('GET', '/api/accounts', null, self::OTHER);

        $this->assertSame(403, $r['status']);
        $this->assertSame('forbidden', $r['json']['code']);
        $this->assertArrayHasKey('error', $r['json']);
    }

    public function testListeZeigtKontenMitEmailAnmeldenameStatusUndEingeladenAm(): void
    {
        $this->asAdmin();
        $this->app->createAccount(self::OTHER, 'invited', 'neu@schule.test', 'neu');

        $r = $this->call('GET', '/api/accounts');

        $this->assertSame(200, $r['status']);
        $byId = array_column($r['json']['accounts'], null, 'id');
        $this->assertSame(
            ['id' => self::ADMIN, 'email' => 'admin@schule.test', 'login_name' => 'admin@schule.test', 'status' => 'active', 'invited_at' => null],
            $byId[self::ADMIN],
        );
        $this->assertSame('invited', $byId[self::OTHER]['status']);
        $this->assertSame('neu', $byId[self::OTHER]['login_name']);
    }

    public function testListeNurMitViewAberOhneInviteSiehtEinladenNichtErlaubt(): void
    {
        $this->app->createAccount(self::OTHER, 'active', 'x@schule.test');
        $this->app->grantPermission(self::OTHER, 'accounts.view');

        $this->assertSame(200, $this->call('GET', '/api/accounts', null, self::OTHER)['status']);
        $r = $this->invite(['email' => 'a@schule.test'], self::OTHER);
        $this->assertSame(403, $r['status']);
        $this->assertSame('forbidden', $r['json']['code']);
        $this->assertSame([], $this->stub->inviteCalls());
    }

    public function testEinladenPerEmailLegtKontoAnUndSchreibtAuditMitDemEinladendenKontoAlsAkteur(): void
    {
        $this->asAdmin();

        $r = $this->invite(['email' => 'neu@schule.test']);

        $this->assertSame(201, $r['status'], $r['body']);
        $this->assertSame('neu@schule.test', $r['json']['account']['email']);
        $this->assertSame('neu@schule.test', $r['json']['account']['login_name']);
        $this->assertSame('invited', $r['json']['account']['status']);
        $this->assertNotNull($r['json']['account']['invited_at']);
        $this->assertNull($r['json']['invite_code']);

        $calls = $this->stub->inviteCalls();
        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['signature_valid']);
        $this->assertSame('neu@schule.test', $calls[0]['body']['email']);

        $local = array_column($this->app->accounts(), null, 'public_user_id');
        $this->assertSame('invited', $local[$r['json']['account']['id']]['status']);

        $audit = $this->app->auditLog();
        $this->assertCount(1, $audit);
        $this->assertSame('account.invited', $audit[0]['action']);
        $this->assertSame('account', $audit[0]['actor_type']);
        $this->assertSame(self::ADMIN, $audit[0]['actor_account_id']);
        $this->assertSame($r['json']['account']['id'], $audit[0]['object_id']);
        $this->assertStringNotContainsString('neu@schule.test', json_encode($audit));
    }

    public function testEinladenMitEigenemAnmeldenamenSendetIhnAnDenAnmeldedienstUndSpeichertIhn(): void
    {
        $this->asAdmin();

        $r = $this->invite(['email' => 'neu@schule.test', 'login_name' => 'max.muster']);

        $this->assertSame(201, $r['status'], $r['body']);
        $this->assertSame('max.muster', $r['json']['account']['login_name']);
        $this->assertSame('max.muster', $this->stub->inviteCalls()[0]['body']['username']);
    }

    public function testEinladungscodeAusDemAnmeldedienstWirdEinmaligDurchgereicht(): void
    {
        $this->asAdmin();
        $this->stub->script('invite', ['body' => ['ok' => true, 'public_user_id' => self::OTHER, 'created' => true, 'already_active' => false, 'invite_code' => 'ABCD-EFGH-JKMN-PQRS']]);

        $r = $this->invite(['email' => 'neu@schule.test']);

        $this->assertSame('ABCD-EFGH-JKMN-PQRS', $r['json']['invite_code']);
        $this->assertStringNotContainsString('ABCD', json_encode($this->app->accounts()) . json_encode($this->app->auditLog()));
    }

    public function testEinladenOhneOderMitUngueltigerEmailIstEinFehlerAufrufOhneAnmeldedienst(): void
    {
        $this->asAdmin();

        $none = $this->invite(['login_name' => 'nur-name']);
        $bad = $this->invite(['email' => 'keine-mail']);

        $this->assertSame([400, 'email_required'], [$none['status'], $none['json']['code']]);
        $this->assertSame([400, 'invalid_email'], [$bad['status'], $bad['json']['code']]);
        $this->assertSame([], $this->stub->inviteCalls());
        $this->assertCount(1, $this->app->accounts());
    }

    public function testErneutesEinladenDerEmailEinesEingeladenenKontosIstGefahrlosUndErzeugtKeinZweitesKonto(): void
    {
        $this->asAdmin();
        $first = $this->invite(['email' => 'neu@schule.test']);

        $second = $this->invite(['email' => 'neu@schule.test']);

        $this->assertSame(201, $second['status']);
        $this->assertSame($first['json']['account']['id'], $second['json']['account']['id']);
        $this->assertCount(2, $this->app->accounts());
        $this->assertSame(['account.invited', 'account.reinvited'], $this->auditActions());
    }

    public function testVergebenerAnmeldenameUndEmailGeben409OhneAnmeldedienstAufruf(): void
    {
        $this->asAdmin();
        $this->app->createAccount(self::OTHER, 'invited', 'x@schule.test', 'max');

        $name = $this->invite(['email' => 'neu@schule.test', 'login_name' => 'max']);
        $mail = $this->invite(['email' => 'x@schule.test', 'login_name' => 'anders']);

        $this->assertSame([409, 'username_taken'], [$name['status'], $name['json']['code']]);
        $this->assertSame([409, 'email_taken'], [$mail['status'], $mail['json']['code']]);
        $this->assertArrayHasKey('error', $name['json']);
        $this->assertSame([], $this->stub->inviteCalls());
        $this->assertCount(2, $this->app->accounts());
    }

    public function testLokalAktivesKontoEinladenGibt409AlreadyActiveOhneAnmeldedienstAufruf(): void
    {
        $this->asAdmin();

        $r = $this->invite(['email' => 'admin@schule.test']);

        $this->assertSame([409, 'already_active'], [$r['status'], $r['json']['code']]);
        $this->assertSame([], $this->stub->inviteCalls());
    }

    public function testUsernameTakenVomAnmeldedienstGibt409UndLegtNichtsAn(): void
    {
        $this->asAdmin();
        $this->stub->script('invite', ['status' => 409, 'body' => ['error' => 'taken', 'code' => 'username_taken']]);

        $r = $this->invite(['email' => 'neu@schule.test', 'login_name' => 'max']);

        $this->assertSame([409, 'username_taken'], [$r['status'], $r['json']['code']]);
        $this->assertCount(1, $this->app->accounts());
        $this->assertSame([], $this->app->auditLog());
    }

    public function testUngueltigerAnmeldenameVomAnmeldedienstGibt400InvalidUsername(): void
    {
        $this->asAdmin();
        $this->stub->script('invite', ['status' => 400, 'body' => ['error' => 'bad', 'code' => 'invalid_username']]);

        $r = $this->invite(['email' => 'neu@schule.test', 'login_name' => 'A B']);

        $this->assertSame([400, 'invalid_username'], [$r['status'], $r['json']['code']]);
        $this->assertCount(1, $this->app->accounts());
    }

    /** @return array<string,array{array}> */
    public static function ausfaelle(): array
    {
        return ['5xx' => [['status' => 500]], 'Timeout' => [['delay' => 1.0]], 'Müll' => [['status' => 200, 'body' => ['nix' => 1]]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ausfaelle')]
    public function testAusfallDesAnmeldedienstesGibt502OhneHalbenZustandUndWiederholungFunktioniert(array $scripted): void
    {
        $this->asAdmin();
        $this->stub->script('invite', $scripted);

        $r = $this->invite(['email' => 'neu@schule.test']);

        $this->assertSame([502, 'auth_unavailable'], [$r['status'], $r['json']['code']]);
        $this->assertCount(1, $this->app->accounts());
        $this->assertSame([], $this->app->auditLog());

        $retry = $this->invite(['email' => 'neu@schule.test']);
        $this->assertSame(201, $retry['status']);
        $this->assertCount(2, $this->app->accounts());
        $this->assertSame(['account.invited'], $this->auditActions());
    }

    public function testErneutEinladenErneuertDieEinladungUndSchreibtAuditMitAkteur(): void
    {
        $this->asAdmin();
        $this->app->createAccount(self::OTHER, 'invited', 'neu@schule.test', 'neu');
        $this->stub->script('invite', ['body' => ['ok' => true, 'public_user_id' => self::OTHER, 'created' => false, 'already_active' => false]]);

        $r = $this->call('POST', '/api/accounts/' . self::OTHER . '/reinvite');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(self::OTHER, $r['json']['account']['id']);
        $this->assertNotNull($r['json']['account']['invited_at']);

        $calls = $this->stub->inviteCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('neu@schule.test', $calls[0]['body']['email']);
        $this->assertSame('neu', $calls[0]['body']['username']);

        $audit = $this->app->auditLog();
        $this->assertSame(['account.reinvited'], array_column($audit, 'action'));
        $this->assertSame(['account', self::ADMIN, self::OTHER], [$audit[0]['actor_type'], $audit[0]['actor_account_id'], $audit[0]['object_id']]);
    }

    public function testErneutEinladenOhneEigenenAnmeldenamenSendetNurDieEmail(): void
    {
        $this->asAdmin();
        $this->app->createAccount(self::OTHER, 'invited', 'neu@schule.test');

        $this->call('POST', '/api/accounts/' . self::OTHER . '/reinvite');

        $this->assertArrayNotHasKey('username', $this->stub->inviteCalls()[0]['body']);
    }

    public function testErneutEinladenAktivesOderInaktivesKontoGibt409OhneAnmeldedienstAufruf(): void
    {
        $this->asAdmin();

        $active = $this->call('POST', '/api/accounts/' . self::ADMIN . '/reinvite');
        $this->app->createAccount(self::OTHER, 'inactive', 'x@schule.test');
        $inactive = $this->call('POST', '/api/accounts/' . self::OTHER . '/reinvite');

        $this->assertSame([409, 'already_active'], [$active['status'], $active['json']['code']]);
        $this->assertSame([409, 'account_inactive'], [$inactive['status'], $inactive['json']['code']]);
        $this->assertSame([], $this->stub->inviteCalls());
        $this->assertSame([], $this->app->auditLog());
    }

    public function testErneutEinladenUnbekanntesKontoGibt404UndOhneRecht403(): void
    {
        $this->asAdmin();
        $this->app->createAccount(self::OTHER, 'invited', 'x@schule.test');
        $this->app->grantPermission(self::OTHER, 'accounts.view');

        $unknown = $this->call('POST', '/api/accounts/99999999-9999-4999-8999-999999999999/reinvite');
        $forbidden = $this->call('POST', '/api/accounts/' . self::OTHER . '/reinvite', null, self::OTHER);

        $this->assertSame([404, 'account_not_found'], [$unknown['status'], $unknown['json']['code']]);
        $this->assertSame([403, 'forbidden'], [$forbidden['status'], $forbidden['json']['code']]);
        $this->assertSame([], $this->stub->inviteCalls());
    }

    public function testErneutEinladenBeiAusfallGibt502UndLaesstDasKontoUnveraendert(): void
    {
        $this->asAdmin();
        $this->app->createAccount(self::OTHER, 'invited', 'x@schule.test');
        $before = $this->app->accounts();
        $this->stub->script('invite', ['status' => 500]);

        $r = $this->call('POST', '/api/accounts/' . self::OTHER . '/reinvite');

        $this->assertSame([502, 'auth_unavailable'], [$r['status'], $r['json']['code']]);
        $this->assertSame($before, $this->app->accounts());
        $this->assertSame([], $this->app->auditLog());
    }
}
