<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Security;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * P3 "Letzter Schuladmin bleibt erhalten" (Ticket #35): Entziehen der Rolle Schuladmin ueber
 * POST /api/accounts/{id}/roles/{roleId}/revoke und die Lesesicht GET /api/accounts/{id}/access,
 * ueber HTTP (php-cgi + MariaDB). Nur Konten mit Status `active` zaehlen.
 */
final class LastAdminTest extends TestCase
{
    private const A = '11111111-1111-4111-8111-111111111111';
    private const B = '22222222-2222-4222-8222-222222222222';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
    }

    private function admin(string $id, string $status = 'active'): void
    {
        $this->app->createAccount($id, $status);
        $this->app->assignRole($id, 'schuladmin');
    }

    private function adminRoleId(): string
    {
        $stmt = $this->app->pdo()->prepare('SELECT `id` FROM `permission_roles` WHERE `role_key` = ?');
        $stmt->execute(['schuladmin']);
        return (string) $stmt->fetchColumn();
    }

    /** Der Akteur $actor entzieht $target die Rolle Schuladmin. */
    private function revokeAdmin(string $actor, string $target): array
    {
        return $this->app->request('POST', "/api/accounts/$target/roles/{$this->adminRoleId()}/revoke", ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($actor)]);
    }

    /** @return list<string> */
    private function activeAdmins(): array
    {
        return array_map('strval', $this->app->pdo()->query(
            "SELECT a.`public_user_id` FROM `accounts` a JOIN `permission_user_roles` ur ON ur.`user_uuid` = a.`public_user_id`
             JOIN `permission_roles` r ON r.`id` = ur.`role_id` WHERE r.`role_key` = 'schuladmin' AND a.`status` = 'active' ORDER BY a.`id`"
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testDemLetztenAktivenSchuladminDieRolleEntziehenIst409AuchBeiSichSelbst(): void
    {
        $this->admin(self::A);

        $r = $this->revokeAdmin(self::A, self::A);

        $this->assertSame(409, $r['status']);
        $this->assertSame('last_admin', $r['json']['code']);
        $this->assertSame([self::A], $this->activeAdmins());
        $this->assertSame([], $this->app->auditLog(), 'abgelehnt heisst: kein Audit-Eintrag');
    }

    public function testGibtEsEinenWeiterenAktivenSchuladminIstEntziehenErlaubtAuchBeiSichSelbst(): void
    {
        $this->admin(self::A);
        $this->admin(self::B);

        $r = $this->revokeAdmin(self::A, self::A);

        $this->assertSame(200, $r['status']);
        $this->assertSame([self::B], $this->activeAdmins());
        $this->assertSame(['role.revoked'], array_column($this->app->auditLog(), 'action'));
    }

    public function testEinNurEingeladenerZweiterAdminHebtDieSperreNichtAuf(): void
    {
        $this->admin(self::A);
        $this->admin(self::B, 'invited');

        $r = $this->revokeAdmin(self::A, self::A);

        $this->assertSame(409, $r['status']);
        $this->assertSame('last_admin', $r['json']['code']);
    }

    public function testEinDeaktivierterZweiterAdminHebtDieSperreNichtAuf(): void
    {
        $this->admin(self::A);
        $this->admin(self::B, 'inactive');

        $this->assertSame('last_admin', $this->revokeAdmin(self::A, self::A)['json']['code']);
    }

    public function testEinEingeladenerAdminDarfDieRolleVerlierenDaSieDenBestandNichtAendert(): void
    {
        $this->admin(self::A);
        $this->admin(self::B, 'invited');

        $r = $this->revokeAdmin(self::A, self::B);

        $this->assertSame(200, $r['status']);
        $this->assertSame([self::A], $this->activeAdmins());
    }

    public function testLesesichtSperrtEntziehenBeimLetztenAdminMitCodeUndBegruendung(): void
    {
        $this->admin(self::A);

        $r = $this->app->request('GET', '/api/accounts/' . self::A . '/access', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::A)]);

        $action = array_column($r['json']['role_actions'], null, 'role_key')['schuladmin'];
        $this->assertSame('revoke', $action['action']);
        $this->assertFalse($action['allowed']);
        $this->assertSame('last_admin', $action['code']);
        $this->assertNotEmpty($action['reason']);
    }

    public function testLesesichtErlaubtEntziehenSobaldEsEinenWeiterenAktivenAdminGibt(): void
    {
        $this->admin(self::A);
        $this->admin(self::B);

        $r = $this->app->request('GET', '/api/accounts/' . self::A . '/access', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::A)]);

        $action = array_column($r['json']['role_actions'], null, 'role_key')['schuladmin'];
        $this->assertTrue($action['allowed']);
        $this->assertNull($action['code']);
    }

    // ---- Der Baustein als eigene Operation (fuer die spaetere Deaktivierung) ----------------

    /** @return array{code:int,out:string,err:string} */
    private function ensureAdminRemains(string ...$leaving): array
    {
        return $this->app->cli('tests/Support/ensure-admin-remains.php', $leaving);
    }

    public function testEnsureAdminRemainsLehntAbWennAlleAktivenAdminsAusscheidenWuerden(): void
    {
        $this->admin(self::A);
        $this->admin(self::B);

        $ok = $this->ensureAdminRemains(self::A);
        $this->assertSame([0, 'ok'], [$ok['code'], trim($ok['out'])]);
        $refused = $this->ensureAdminRemains(self::A, self::B);
        $this->assertSame([3, 'last_admin'], [$refused['code'], trim($refused['out'])]);
    }

    public function testEnsureAdminRemainsZaehltNurAktiveUndIgnoriertKontenOhneAdminRolle(): void
    {
        $this->admin(self::A);
        $this->admin(self::B, 'invited');
        $this->app->createAccount('33333333-3333-4333-8333-333333333333', 'active');

        $this->assertSame('last_admin', trim($this->ensureAdminRemains(self::A)['out']), 'eingeladener Admin zaehlt nicht');
        $this->assertSame('ok', trim($this->ensureAdminRemains(self::B)['out']), 'ein eingeladener Admin aendert den Bestand nicht');
        $this->assertSame('ok', trim($this->ensureAdminRemains('33333333-3333-4333-8333-333333333333')['out']), 'Konto ohne Admin-Rolle');
        $this->assertSame('ok', trim($this->ensureAdminRemains()['out']), 'nichts scheidet aus');
    }

    // ---- Nebenlaeufigkeit -------------------------------------------------------------------

    /**
     * Macht das Fenster zwischen Pruefung und Commit gross: Jedes Loeschen einer Rollenzuweisung dauert 1,5 s.
     * Ohne Sperre pruefen dann beide Requests, bevor einer von beiden committet, und entziehen beide.
     */
    private function slowDownRoleRemoval(): void
    {
        $this->app->pdo()->exec('DROP TRIGGER IF EXISTS `t35_slow_role_removal`');
        $this->app->pdo()->exec('CREATE TRIGGER `t35_slow_role_removal` BEFORE DELETE ON `permission_user_roles` FOR EACH ROW SET @t35 = SLEEP(1.5)');
    }

    protected function tearDown(): void
    {
        $this->app->pdo()->exec('DROP TRIGGER IF EXISTS `t35_slow_role_removal`');
    }

    /** @param list<array{string,string}> $revocations je [Akteur, Zielkonto] */
    private function revokeAdminConcurrently(array $revocations): array
    {
        $roleId = $this->adminRoleId();
        return $this->app->requestConcurrently(array_map(
            fn (array $r) => ['POST', "/api/accounts/$r[1]/roles/$roleId/revoke", ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($r[0])], null],
            $revocations,
        ));
    }

    public function testZweiAdminsDieEinanderGleichzeitigDieRolleEntziehenLassenEinenUebrig(): void
    {
        $this->admin(self::A);
        $this->admin(self::B);
        $this->slowDownRoleRemoval();

        $responses = $this->revokeAdminConcurrently([[self::A, self::B], [self::B, self::A]]);

        $this->assertExactlyOneRevocationSucceeded($responses);
        $this->assertSame(['role.revoked'], array_column($this->app->auditLog(), 'action'));
    }

    public function testZweiAdminsDieJeweilsSichSelbstGleichzeitigDieRolleEntziehenLassenEinenUebrig(): void
    {
        $this->admin(self::A);
        $this->admin(self::B);
        $this->slowDownRoleRemoval();

        $responses = $this->revokeAdminConcurrently([[self::A, self::A], [self::B, self::B]]);

        $this->assertExactlyOneRevocationSucceeded($responses);
    }

    // ---- revokeAllRoles prueft P3 ----------------------------------------------------------

    public function testRevokeAllRolesLehntBeimLetztenAktivenSchuladminAbUndAendertNichts(): void
    {
        $this->admin(self::A);

        $r = $this->app->cli('tests/Support/revoke-all-roles.php', [self::A]);

        $this->assertSame([3, 'last_admin'], [$r['code'], trim($r['out'])]);
        $this->assertSame([self::A], $this->activeAdmins());
        $this->assertSame([], $this->app->auditLog());
    }

    public function testRevokeAllRolesEntziehtEinemAdminNebenEinemWeiterenAktivenAdmin(): void
    {
        $this->admin(self::A);
        $this->admin(self::B);

        $r = $this->app->cli('tests/Support/revoke-all-roles.php', [self::A]);

        $this->assertSame([0, 'ok'], [$r['code'], trim($r['out'])]);
        $this->assertSame([self::B], $this->activeAdmins());
    }

    /**
     * Genau ein Request gelingt, genau ein aktiver Admin bleibt. Der andere scheitert mit `last_admin` (409) oder, wenn der
     * erste ihm vorher die Rolle genommen hat, mit `permission_not_held` (403): auch das ist richtig, ein Konto
     * ohne Schuladmin-Rolle darf sie niemandem entziehen.
     *
     * @param list<array{status:int,json:?array}> $responses
     */
    private function assertExactlyOneRevocationSucceeded(array $responses): void
    {
        $outcomes = array_map(fn (array $r) => $r['status'] . ' ' . ($r['json']['code'] ?? '-'), $responses);
        $this->assertSame(1, count(array_filter($responses, fn (array $r) => $r['status'] === 200)), 'Ergebnisse: ' . implode(', ', $outcomes));
        $this->assertContains(
            array_values(array_filter($outcomes, fn (string $o) => $o !== '200 -'))[0] ?? '',
            ['409 last_admin', '403 permission_not_held'],
        );
        $this->assertCount(1, $this->activeAdmins(), 'Ergebnisse: ' . implode(', ', $outcomes));
    }
}
