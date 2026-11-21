<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Accounts;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\AuthStub;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Erster Schuladmin (Ticket #25): CLI bin/invite-first-admin.php als Unterprozess gegen
 * die Test-DB und den Auth-Stub an der Netzwerkgrenze (AUTH_SERVICE_URL).
 * Beobachtet werden Exit-Code und Ausgabe, DB-Zustand, die Aufrufe am Stub und /api/me.
 */
final class FirstSchuladminCliTest extends TestCase
{
    private const EMAIL = 'admin@schule.test';

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

    /** @return array{code:int, out:string, err:string} */
    private function invite(array $args = ['--email=' . self::EMAIL], array $env = []): array
    {
        return $this->app->cli('bin/invite-first-admin.php', $args, $env + $this->stub->env());
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        return array_column($this->app->auditLog(), 'action');
    }

    private function meAsCode(string $sub): string
    {
        return $this->meAs($sub)['json']['code'] ?? 'ok';
    }

    private function meAs(string $sub): array
    {
        return $this->app->request('GET', '/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)]);
    }

    public function testLaedtErstenSchuladminPerEmailEinUndLegtKontoRolleUndAuditAn(): void
    {
        $r = $this->invite();

        $this->assertSame(0, $r['code'], $r['out'] . $r['err']);

        $calls = $this->stub->inviteCalls();
        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['signature_valid']);
        $this->assertSame('user.invite', $calls[0]['body']['event']);
        $this->assertSame(self::EMAIL, $calls[0]['body']['email']);

        $accounts = $this->app->accounts();
        $this->assertCount(1, $accounts);
        $this->assertSame('invited', $accounts[0]['status']);
        $this->assertSame(self::EMAIL, $accounts[0]['email']);
        $this->assertSame(self::EMAIL, $accounts[0]['login_name'], 'ohne eigenen Namen ist die E-Mail der Anmeldename');
        $this->assertNotNull($accounts[0]['invited_at']);

        $audit = $this->app->auditLog();
        $this->assertSame(['account.invited', 'role.assigned'], array_column($audit, 'action'));
        foreach ($audit as $entry) {
            $this->assertSame('cli', $entry['actor_type']);
            $this->assertNull($entry['actor_account_id']);
            $this->assertSame('account', $entry['object_type']);
            $this->assertSame($accounts[0]['public_user_id'], $entry['object_id']);
            $this->assertStringNotContainsString(self::EMAIL, json_encode($entry), 'keine E-Mail im Audit-Eintrag');
        }
    }

    public function testErsterSchuladminBekommtMfaRequiredGesetztWeilDieRolleMfaVerlangt(): void
    {
        $this->assertSame(0, $this->invite()['code']);

        $accounts = $this->app->accounts();
        $this->assertSame(1, (int) $accounts[0]['mfa_required']);
    }

    public function testEingeladenerAdminWirdBeiDerErstenAnmeldungAktivMitAllenRechten(): void
    {
        $this->invite();
        $sub = $this->app->accounts()[0]['public_user_id'];

        $r = $this->meAs($sub);

        $this->assertSame(200, $r['status']);
        $this->assertSame('active', $r['json']['account']['status']);
        $this->assertEqualsCanonicalizing(['accounts.view', 'accounts.invite', 'accounts.deactivate', 'accounts.revoke_sessions', 'accounts.reset_mfa', 'roles.manage', 'roles.assign', 'permissions.assign', 'school_years.view', 'school_years.manage', 'classes.view', 'classes.manage', 'subjects.view', 'subjects.manage', 'school_settings.manage'], $r['json']['permissions']);
        $this->assertSame('active', $this->app->accounts()[0]['status']);
        $this->assertSame(['account.invited', 'role.assigned', 'account.activated'], $this->auditActions());
        $activated = $this->app->auditLog()[2];
        $this->assertSame('account', $activated['actor_type']);
        $this->assertSame($sub, $activated['actor_account_id']);
    }

    private function activeAdmin(string $sub, string $email): void
    {
        $this->app->createAccount($sub, 'active', $email);
        $this->app->assignRole($sub, 'schuladmin');
    }

    public function testLehntAbSobaldEinAktiverSchuladminExistiertUndLegtNichtsAn(): void
    {
        $this->activeAdmin('33333333-3333-4333-8333-333333333333', 'alt@schule.test');

        $r = $this->invite();

        $this->assertSame(3, $r['code']);
        $this->assertStringContainsString('admin_exists', $r['err']);
        $this->assertSame([], $this->stub->calls(), 'der Anmeldedienst wird gar nicht erst gefragt');
        $this->assertCount(1, $this->app->accounts());
        $this->assertSame([], $this->app->auditLog());
    }

    public function testMitAllowAdditionalAdminWirdTrotzdemEingeladenUndDerAlteAdminBleibt(): void
    {
        $this->activeAdmin('33333333-3333-4333-8333-333333333333', 'alt@schule.test');

        $r = $this->invite(['--email=' . self::EMAIL, '--allow-additional-admin']);

        $this->assertSame(0, $r['code'], $r['err']);
        $accounts = $this->app->accounts();
        $this->assertSame(['active', 'invited'], array_column($accounts, 'status'));
        $this->assertSame(['account.invited', 'role.assigned'], $this->auditActions());
    }

    public function testNurEingeladenerAdminDarfOhneOptionErneutEingeladenWerden(): void
    {
        $this->assertSame(0, $this->invite()['code']);

        $r = $this->invite();

        $this->assertSame(0, $r['code'], $r['err']);
        $this->assertCount(2, $this->stub->inviteCalls(), 'erneuter Aufruf beim Anmeldedienst (neues Token, neue Mail)');
        $this->assertCount(1, $this->app->accounts());
        $this->assertSame(['account.invited', 'role.assigned', 'account.reinvited'], $this->auditActions(), 'Rolle nicht doppelt vergeben');
    }

    public function testErneutesEinladenAnAndereAdresseErsetztDieAlteEinladung(): void
    {
        $this->assertSame(0, $this->invite()['code']);
        $old = $this->app->accounts()[0]['public_user_id'];

        $r = $this->invite(['--email=neu@schule.test']);

        $this->assertSame(0, $r['code'], $r['err']);
        $accounts = $this->app->accounts();
        $this->assertCount(1, $accounts);
        $this->assertSame('neu@schule.test', $accounts[0]['email']);
        $this->assertNotSame($old, $accounts[0]['public_user_id']);
        $deletes = array_values(array_filter($this->stub->calls(), static fn ($c) => $c['path'] === '/internal/users/delete'));
        $this->assertCount(1, $deletes, 'die alte Einladung wird beim Anmeldedienst ungueltig gemacht');
        $this->assertSame($old, $deletes[0]['body']['public_user_id']);
        $this->assertTrue($deletes[0]['signature_valid']);
        $this->assertSame('unknown_account', $this->meAsCode($old), 'die ersetzte Einladung gibt keinen Zugang');
    }

    public function testDasErsetzenEinerEinladungHinterlaesstEinenAuditEintragFuerDasGeloeschteKonto(): void
    {
        $this->assertSame(0, $this->invite()['code']);
        $old = $this->app->accounts()[0]['public_user_id'];

        $this->assertSame(0, $this->invite(['--email=neu@schule.test'])['code']);

        $new = $this->app->accounts()[0]['public_user_id'];
        $this->assertSame(
            ['account.invited', 'role.assigned', 'role.revoked', 'account.invitation_withdrawn', 'account.reinvited', 'role.assigned'],
            $this->auditActions(),
        );
        $withdrawn = array_values(array_filter($this->app->auditLog(), static fn ($e) => $e['action'] === 'account.invitation_withdrawn'))[0];
        $this->assertSame(['cli', null, 'account', $old], [$withdrawn['actor_type'], $withdrawn['actor_account_id'], $withdrawn['object_type'], $withdrawn['object_id']]);
        $this->assertNotSame($old, $new);
        $this->assertStringNotContainsString('admin@schule.test', json_encode($withdrawn), 'keine E-Mail im Audit-Eintrag');
    }

    public function testDasErsetzenEinerEinladungAuditiertJedeEntzogeneRolleUndJedesZurueckgesetzteEinzelrecht(): void
    {
        $this->assertSame(0, $this->invite()['code']);
        $old = $this->app->accounts()[0]['public_user_id'];
        $this->app->assignRole($old, 'lehrkraft');
        $this->app->grantPermission($old, 'accounts.invite');
        $this->app->pdo()->prepare("INSERT INTO `permission_user_overrides` (`user_uuid`, `permission`, `mode`) VALUES (?, 'roles.assign', 'removed')")->execute([$old]);
        $this->app->pdo()->exec('DELETE FROM `audit_log`');

        $this->assertSame(0, $this->invite(['--email=neu@schule.test'])['code']);

        $entries = array_values(array_filter(
            $this->app->auditLog(),
            static fn (array $e) => $e['object_id'] === $old && in_array($e['action'], ['role.revoked', 'permission.override_cleared'], true),
        ));
        $contexts = array_map(static fn (array $e) => [$e['action'], $e['actor_type'], json_decode($e['context'], true)], $entries);
        $this->assertEqualsCanonicalizing(
            [
                ['role.revoked', 'cli', ['role' => 'schuladmin', 'name' => 'Schuladmin']],
                ['role.revoked', 'cli', ['role' => 'lehrkraft', 'name' => 'Lehrkraft']],
                ['permission.override_cleared', 'cli', ['permission' => 'accounts.invite', 'mode' => 'added']],
                ['permission.override_cleared', 'cli', ['permission' => 'roles.assign', 'mode' => 'removed']],
            ],
            $contexts,
        );
    }

    /** @return array<string, array{array}> Skripte, mit denen der Anmeldedienst fehlschlaegt. */
    public static function authFailures(): array
    {
        return [
            'Serverfehler' => [['status' => 500, 'body' => ['error' => 'boom']]],
            'Timeout' => [['delay' => 1.5]],
            'unerwartete Antwort' => [['status' => 200, 'body' => ['ok' => true]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authFailures')]
    public function testFehlerDesAnmeldedienstesHinterlaesstKeinenHalbenZustandUndWiederholungFunktioniert(array $scripted): void
    {
        $this->stub->script('invite', $scripted);

        $failed = $this->invite();

        $this->assertSame(4, $failed['code']);
        $this->assertStringContainsString('auth_unavailable', $failed['err']);
        $this->assertSame([], $this->app->accounts());
        $this->assertSame([], $this->app->auditLog());
        $this->assertSame(0, (int) $this->app->pdo()->query('SELECT COUNT(*) FROM `permission_user_roles`')->fetchColumn());

        $this->stub->calls(); // php -S bedient nacheinander: wartet, bis die verzoegerte Antwort ausgeliefert ist
        $retry = $this->invite();

        $this->assertSame(0, $retry['code'], $retry['err']);
        $this->assertCount(1, $this->app->accounts());
        $this->assertSame(['account.invited', 'role.assigned'], $this->auditActions());
    }

    public function testFalschesSecretWirdVomAnmeldedienstAbgelehntUndNichtsEntsteht(): void
    {
        $r = $this->invite(env: ['AUTH_SERVICE_WEBHOOK_SECRET' => 'falsch']);

        $this->assertSame(4, $r['code']);
        $this->assertFalse($this->stub->inviteCalls()[0]['signature_valid']);
        $this->assertSame([], $this->app->accounts());
    }

    public function testAnmeldedienstKenntDieEinladungSchonAberLokalGibtEsKeinKontoDannWirdEsAngelegt(): void
    {
        // z. B. Abbruch nach dem Aufruf des Anmeldedienstes: created=false, lokal noch kein Konto
        $this->stub->script('invite', ['body' => ['ok' => true, 'public_user_id' => '44444444-4444-4444-8444-444444444444', 'created' => false, 'already_active' => false]]);

        $r = $this->invite();

        $this->assertSame(0, $r['code'], $r['err']);
        $this->assertSame('invited', $this->app->accounts()[0]['status']);
        $this->assertSame(['account.invited', 'role.assigned'], $this->auditActions());
    }

    public function testAnmeldedienstMeldetAlreadyActiveOhneLokalesKontoDannWirdEsAlsEingeladenAngelegt(): void
    {
        $this->stub->script('invite', ['body' => ['ok' => true, 'public_user_id' => '44444444-4444-4444-8444-444444444444', 'created' => false, 'already_active' => true]]);

        $r = $this->invite();

        $this->assertSame(0, $r['code'], $r['err']);
        $this->assertSame('invited', $this->app->accounts()[0]['status']);
    }

    public function testLokalAktivesKontoWirdNichtErneutEingeladenOhneDenAnmeldedienstZuRufen(): void
    {
        $this->app->createAccount('55555555-5555-4555-8555-555555555555', 'active', self::EMAIL);

        $r = $this->invite(['--email=' . self::EMAIL, '--allow-additional-admin']);

        $this->assertSame(3, $r['code']);
        $this->assertStringContainsString('already_active', $r['err']);
        $this->assertSame([], $this->stub->calls());
    }

    public function testVergebenerAnmeldenameUndInaktivesKontoGebenKonflikte(): void
    {
        $this->app->createAccount('55555555-5555-4555-8555-555555555555', 'active', 'x@schule.test', 'meister');

        $name = $this->invite(['--email=' . self::EMAIL, '--login-name=meister', '--allow-additional-admin']);
        $this->assertSame(3, $name['code']);
        $this->assertStringContainsString('username_taken', $name['err']);

        $this->app->createAccount('66666666-6666-4666-8666-666666666666', 'inactive', 'gesperrt@schule.test');
        $inactive = $this->invite(['--email=gesperrt@schule.test', '--allow-additional-admin']);
        $this->assertSame(3, $inactive['code']);
        $this->assertStringContainsString('account_inactive', $inactive['err']);

        $this->assertSame([], $this->stub->calls());
        $this->assertSame([], $this->app->auditLog());
    }

    public function testAnmeldedienstMeldetVergebenenAnmeldenamenDannEntstehtNichtsLokal(): void
    {
        $this->stub->script('invite', ['status' => 409, 'body' => ['error' => 'taken', 'code' => 'username_taken']]);

        $r = $this->invite(['--email=' . self::EMAIL, '--login-name=meister']);

        $this->assertSame(3, $r['code']);
        $this->assertStringContainsString('username_taken', $r['err']);
        $this->assertSame([], $this->app->accounts());
    }

    /** @return array<string, array{array, string}> */
    public static function fachlicheAblehnungen(): array
    {
        return [
            'ungueltiger Anmeldename' => [['status' => 400, 'body' => ['error' => 'bad', 'code' => 'invalid_username']], 'invalid_username'],
            'Anmeldename ohne Schalter' => [['status' => 400, 'body' => ['error' => 'off', 'code' => 'username_login_disabled']], 'username_login_disabled'],
            'Konto gesperrt' => [['status' => 409, 'body' => ['error' => 'disabled', 'code' => 'account_disabled']], 'account_disabled'],
            '400 ohne Code' => [['status' => 400, 'body' => ['error' => 'Webhook processing failed.']], 'invalid_request'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fachlicheAblehnungen')]
    public function testFachlicheAblehnungDesAnmeldedienstesIstExitCode3MitCodeUndLegtNichtsAn(array $scripted, string $code): void
    {
        $this->stub->script('invite', $scripted);

        $r = $this->invite();

        $this->assertSame(3, $r['code'], 'fachlich abgelehnt, nicht "Anmeldedienst nicht verfuegbar" (4)');
        $this->assertStringContainsString($code, $r['err']);
        $this->assertSame([], $this->app->accounts());
        $this->assertSame([], $this->app->auditLog());
    }

    public function testEmailUndAnmeldenameWerdenKleingeschriebenGesendetUndGespeichert(): void
    {
        $r = $this->invite(['--email=Admin@Schule.TEST', '--login-name=Frau.Admin']);

        $this->assertSame(0, $r['code'], $r['err']);
        $body = $this->stub->inviteCalls()[0]['body'];
        $this->assertSame(['admin@schule.test', 'frau.admin'], [$body['email'], $body['username']]);
        $account = $this->app->accounts()[0];
        $this->assertSame(['admin@schule.test', 'frau.admin'], [$account['email'], $account['login_name']]);
    }

    public function testUngueltigeEmailIstEinFalscherAufrufOhneAnmeldedienst(): void
    {
        $r = $this->invite(['--email=keine-mail']);

        $this->assertSame(2, $r['code']);
        $this->assertSame([], $this->stub->calls());
    }

    public function testDasAblaufdatumDerEinladungWirdZusammenMitDemCodeAusgegeben(): void
    {
        $this->stub->script('invite', ['body' => [
            'ok' => true, 'public_user_id' => '77777777-7777-4777-8777-777777777777', 'created' => true, 'already_active' => false,
            'username' => 'schueler.eins', 'invite_code' => 'ABCD-EFGH-JKMN-PQRS', 'expires_at' => '2099-01-02T12:34:56Z',
        ]]);

        $r = $this->invite(['--login-name=schueler.eins']);

        $this->assertSame(0, $r['code'], $r['err']);
        $this->assertStringContainsString('ABCD-EFGH-JKMN-PQRS', $r['out']);
        $this->assertStringContainsString('2099-01-02T12:34:56Z', $r['out']);
        $this->assertStringNotContainsString('2099-01-02', json_encode([$this->app->accounts(), $this->app->auditLog()]), 'das Ablaufdatum wird nicht gespeichert');
    }

    public function testAnmeldenameWirdAnDenAnmeldedienstWeitergegebenUndLokalGespeichert(): void
    {
        $r = $this->invite(['--email=' . self::EMAIL, '--login-name=frau.admin']);

        $this->assertSame(0, $r['code'], $r['err']);
        $this->assertSame('frau.admin', $this->stub->inviteCalls()[0]['body']['username']);
        $account = $this->app->accounts()[0];
        $this->assertSame('frau.admin', $account['login_name']);
        $this->assertSame(self::EMAIL, $account['email']);
    }

    public function testAnmeldenameUndEinladungscodeAusDerAntwortWerdenUebernommenUndDerCodeNurEinmalAusgegeben(): void
    {
        // Vertrag ab G6: der Anmeldedienst nennt den endgueltigen Namen und bei Einladung ohne E-Mail einen Code.
        $this->stub->script('invite', ['body' => [
            'ok' => true, 'public_user_id' => '77777777-7777-4777-8777-777777777777', 'created' => true,
            'already_active' => false, 'username' => 'schueler.eins', 'invite_code' => 'ABCD-EFGH-JKMN-PQRS',
        ]]);

        $r = $this->invite(['--login-name=schueler.eins']);

        $this->assertSame(0, $r['code'], $r['err']);
        $this->assertStringContainsString('ABCD-EFGH-JKMN-PQRS', $r['out']);
        $account = $this->app->accounts()[0];
        $this->assertNull($account['email']);
        $this->assertSame('schueler.eins', $account['login_name']);
        $stored = json_encode([$this->app->accounts(), $this->app->auditLog()]);
        $this->assertStringNotContainsString('ABCD-EFGH-JKMN-PQRS', $stored, 'der Code wird nirgends gespeichert');
    }

    public function testOhneEmailUndOhneAnmeldenameIstDerAufrufFalsch(): void
    {
        $r = $this->invite([]);

        $this->assertSame(2, $r['code']);
        $this->assertSame([], $this->stub->calls());
    }
}
