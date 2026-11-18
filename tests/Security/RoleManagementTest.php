<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Shared\Security\StandardRoles;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Rollen anlegen, bearbeiten, loeschen (Ticket #33, ADR 0002 B4 Nachtrag #11) ueber HTTP
 * gegen php-cgi + Test-DB. Alle Aenderungen laufen ueber AccessAdministration.
 */
final class RoleManagementTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    /** roles.manage + accounts.view (kein Wildcard) */
    private const MANAGER = '22222222-2222-4222-8222-222222222222';
    /** nur roles.assign */
    private const ASSIGNER = '33333333-3333-4333-8333-333333333333';
    private const MEMBER = '44444444-4444-4444-8444-444444444444';
    private const MEMBER2 = '55555555-5555-4555-8555-555555555555';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'roles.manage');
        $this->app->grantPermission(self::MANAGER, 'accounts.view');
        $this->app->createAccount(self::ASSIGNER, 'active');
        $this->app->grantPermission(self::ASSIGNER, 'roles.assign');
    }

    protected function tearDown(): void
    {
        // Die Test-DB gilt fuer den ganzen Lauf: eigene Rollen entfernen, Standardrollen zuruecksetzen.
        $pdo = $this->app->pdo();
        $pdo->exec("DELETE FROM `permission_roles` WHERE `role_key` LIKE 'r\\_%'");
        foreach (StandardRoles::definitions() as $d) {
            if ($d['key'] === 'schuladmin') {
                continue;
            }
            $pdo->prepare('UPDATE `permission_roles` SET `name` = ?, `description` = ? WHERE `role_key` = ?')->execute([$d['name'], $d['description'], $d['key']]);
            $pdo->prepare('DELETE FROM `permission_role_permissions` WHERE `role_id` = (SELECT `id` FROM `permission_roles` WHERE `role_key` = ?)')->execute([$d['key']]);
        }
    }

    /** @return array{status:int, body:string, json:?array} */
    private function call(string $method, string $uri, ?array $json = null, string $sub = self::ADMIN): array
    {
        return $this->app->request($method, $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)], $json);
    }

    /** @return array<string,mixed> */
    private function create(array $body = [], string $sub = self::ADMIN): array
    {
        $r = $this->call('POST', '/api/roles', $body + ['name' => 'Fachbereichsleitung', 'description' => 'Leitet einen Fachbereich', 'permissions' => ['accounts.view']], $sub);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json']['role'];
    }

    /** @return array<string,mixed> */
    private function roleByKey(string $key): array
    {
        return array_column($this->call('GET', '/api/roles')['json']['roles'], null, 'key')[$key];
    }

    private function mfaRequired(string $sub): bool
    {
        return (bool) array_column($this->app->accounts(), 'mfa_required', 'public_user_id')[$sub];
    }

    /** @return list<array<string,mixed>> Audit-Eintraege der Handlung mit dekodiertem Kontext */
    private function audit(string $action): array
    {
        $rows = array_values(array_filter($this->app->auditLog(), static fn (array $r) => $r['action'] === $action));
        return array_map(static fn (array $r) => $r + ['ctx' => json_decode((string) $r['context'], true)], $rows);
    }

    // --- Anlegen ---

    public function testVerwalterLegtRolleAnUndAPIVergibtUnveraenderlichenZufallsschluessel(): void
    {
        $role = $this->create(['permissions' => ['accounts.view', 'accounts.invite']]);

        $this->assertMatchesRegularExpression('/^r_[0-9a-f]{16}$/', $role['key']);
        $this->assertSame('Fachbereichsleitung', $role['name']);
        $this->assertSame('Leitet einen Fachbereich', $role['description']);
        $this->assertEqualsCanonicalizing(['accounts.view', 'accounts.invite'], $role['permissions']);
        $this->assertTrue($role['requires_mfa'], 'Erfordert MFA ist standardmaessig an');
        $this->assertSame('custom', $role['protection']);
        $this->assertSame(0, $role['account_count']);
        $this->assertSame($role, $this->roleByKey($role['key']));

        $other = $this->create(['name' => 'Zweite']);
        $this->assertNotSame($role['key'], $other['key']);
    }

    public function testRolleOhneMfaAnlegen(): void
    {
        $role = $this->create(['requires_mfa' => false]);

        $this->assertFalse($role['requires_mfa']);
    }

    public function testAnlegenSchreibtAuditEintragMitSchluesselUndName(): void
    {
        $role = $this->create();

        $entries = $this->audit('role.created');
        $this->assertCount(1, $entries);
        $this->assertSame('account', $entries[0]['actor_type']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame('role', $entries[0]['object_type']);
        $this->assertSame($role['id'], $entries[0]['object_id']);
        $this->assertSame(['role' => $role['key'], 'name' => 'Fachbereichsleitung'], $entries[0]['ctx']);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function ungueltigeEingaben(): iterable
    {
        yield 'leerer Name' => [['name' => '   ']];
        yield 'Name kein Text' => [['name' => 5]];
        yield 'Name zu lang' => [['name' => str_repeat('a', 121)]];
        yield 'Beschreibung zu lang' => [['description' => str_repeat('a', 501)]];
        yield 'unbekanntes Recht' => [['permissions' => ['gibt.es.nicht']]];
        yield 'Wildcard' => [['permissions' => ['*']]];
        yield 'Rechte keine Liste' => [['permissions' => 'accounts.view']];
        yield 'MFA kein Bool' => [['requires_mfa' => 'ja']];
    }

    /** @param array<string,mixed> $override */
    #[DataProvider('ungueltigeEingaben')]
    public function testUngueltigeEingabeErgibt400InvalidRequestUndLegtNichtsAn(array $override): void
    {
        $r = $this->call('POST', '/api/roles', $override + ['name' => 'X', 'description' => null, 'permissions' => []]);

        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertCount(6, $this->call('GET', '/api/roles')['json']['roles']);
    }

    // --- Berechtigung ---

    public function testVerwalterMitNurRolesAssignKannKeineRolleAendern(): void
    {
        $role = $this->create();
        $before = $this->call('GET', '/api/roles')['json']['roles'];

        $calls = [
            ['POST', '/api/roles', ['name' => 'Neu', 'permissions' => []]],
            ['POST', '/api/roles/' . $role['id'] . '/update', ['name' => 'Umbenannt']],
            ['POST', '/api/roles/' . $role['id'] . '/delete', null],
        ];
        foreach ($calls as [$method, $uri, $body]) {
            $r = $this->call($method, $uri, $body, self::ASSIGNER);
            $this->assertSame(403, $r['status'], "$method $uri");
            $this->assertSame('forbidden', $r['json']['code'], "$method $uri");
        }

        $this->assertSame($before, $this->call('GET', '/api/roles')['json']['roles']);
    }

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('POST', '/api/roles', [], ['name' => 'X'])['status']);
        $this->assertSame(401, $this->app->request('POST', '/api/roles/x/update', [], ['name' => 'X'])['status']);
        $this->assertSame(401, $this->app->request('POST', '/api/roles/x/delete')['status']);
    }

    // --- P2 beim Anlegen ---

    public function testOhneWildcardLassenSichBeimAnlegenNurEigeneRechteVergeben(): void
    {
        $r = $this->call('POST', '/api/roles', ['name' => 'Zu viel', 'permissions' => ['accounts.view', 'accounts.invite']], self::MANAGER);

        $this->assertSame(403, $r['status']);
        $this->assertSame('permission_not_held', $r['json']['code']);
        $this->assertCount(6, $this->call('GET', '/api/roles')['json']['roles']);

        $ok = $this->call('POST', '/api/roles', ['name' => 'Passt', 'permissions' => ['accounts.view']], self::MANAGER);
        $this->assertSame(201, $ok['status'], $ok['body']);
    }

    // --- Bearbeiten ---

    public function testBearbeitenAendertNameBeschreibungUndRechteUndBehaeltDenSchluessel(): void
    {
        $role = $this->create(['permissions' => ['accounts.view', 'accounts.invite']]);

        $r = $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['name' => 'Neuer Name', 'description' => '', 'permissions' => ['accounts.invite', 'roles.assign']]);

        $this->assertSame(200, $r['status'], $r['body']);
        $updated = $r['json']['role'];
        $this->assertSame($role['key'], $updated['key']);
        $this->assertSame($role['id'], $updated['id']);
        $this->assertSame('Neuer Name', $updated['name']);
        $this->assertNull($updated['description']);
        $this->assertEqualsCanonicalizing(['accounts.invite', 'roles.assign'], $updated['permissions']);
        $this->assertSame($updated, $this->roleByKey($role['key']));
    }

    public function testNichtGenannteFelderBleibenBeimBearbeitenUnveraendert(): void
    {
        $role = $this->create(['permissions' => ['accounts.view']]);

        $r = $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['name' => 'Nur Name']);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame('Leitet einen Fachbereich', $r['json']['role']['description']);
        $this->assertSame(['accounts.view'], $r['json']['role']['permissions']);
        $this->assertTrue($r['json']['role']['requires_mfa']);
    }

    public function testBearbeitenSchreibtAuditMitHinzugefuegtenUndEntferntenRechten(): void
    {
        $role = $this->create(['permissions' => ['accounts.view', 'accounts.invite']]);

        $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['name' => 'Neuer Name', 'permissions' => ['accounts.invite', 'roles.assign']]);

        $entries = $this->audit('role.updated');
        $this->assertCount(1, $entries);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame($role['id'], $entries[0]['object_id']);
        $this->assertSame($role['key'], $entries[0]['ctx']['role']);
        $this->assertSame('Neuer Name', $entries[0]['ctx']['name']);
        $this->assertSame('roles.assign', $entries[0]['ctx']['added']);
        $this->assertSame('accounts.view', $entries[0]['ctx']['removed']);
    }

    public function testBearbeitenOhneAenderungSchreibtKeinenAuditEintrag(): void
    {
        $role = $this->create();

        $r = $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['name' => $role['name'], 'permissions' => $role['permissions'], 'requires_mfa' => true]);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertCount(0, $this->audit('role.updated'));
    }

    public function testUngueltigeEingabeBeimBearbeitenErgibt400(): void
    {
        $role = $this->create();

        foreach ([['name' => ''], ['permissions' => ['*']], ['permissions' => ['gibt.es.nicht']]] as $body) {
            $r = $this->call('POST', '/api/roles/' . $role['id'] . '/update', $body);
            $this->assertSame(400, $r['status'], json_encode($body));
            $this->assertSame('invalid_request', $r['json']['code']);
        }
        $this->assertSame($role, $this->roleByKey($role['key']));
    }

    public function testUnbekannteRolleErgibt404RoleNotFound(): void
    {
        foreach ([['update', ['name' => 'X']], ['delete', null]] as [$action, $body]) {
            $r = $this->call('POST', '/api/roles/00000000-0000-4000-8000-000000000000/' . $action, $body);
            $this->assertSame(404, $r['status'], $action);
            $this->assertSame('role_not_found', $r['json']['code'], $action);
        }
    }

    // --- MFA-Merkmal ---

    public function testMfaMerkmalAendernRechnetMfaRequiredAllerBetroffenenKontenNeu(): void
    {
        $role = $this->create();
        $other = $this->create(['name' => 'Mit MFA']);
        foreach ([self::MEMBER, self::MEMBER2] as $id) {
            $this->app->createAccount($id, 'active');
            $this->app->assignRole($id, $role['key']);
        }
        $this->app->assignRole(self::MEMBER2, $other['key']);
        $this->app->pdo()->exec('UPDATE `accounts` SET `mfa_required` = 1 WHERE `public_user_id` IN (\'' . self::MEMBER . '\', \'' . self::MEMBER2 . '\')');

        $off = $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['requires_mfa' => false]);

        $this->assertSame(200, $off['status'], $off['body']);
        $this->assertFalse($off['json']['role']['requires_mfa']);
        $this->assertFalse($this->mfaRequired(self::MEMBER), 'einzige Rolle ohne MFA');
        $this->assertTrue($this->mfaRequired(self::MEMBER2), 'ODER ueber alle Rollen: die zweite Rolle verlangt MFA');

        $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['requires_mfa' => true]);
        $this->assertTrue($this->mfaRequired(self::MEMBER));
        $this->assertCount(2, $this->audit('role.updated'));
    }

    // --- Schutzstufen ---

    /** @return iterable<string,array{string}> */
    public static function unbearbeitbareRollen(): iterable
    {
        yield 'schuladmin' => ['schuladmin'];
        yield 'schueler' => ['schueler'];
    }

    #[DataProvider('unbearbeitbareRollen')]
    public function testSchuladminUndSchuelerSindNichtBearbeitbar(string $key): void
    {
        $before = $this->roleByKey($key);

        $r = $this->call('POST', '/api/roles/' . $before['id'] . '/update', ['name' => 'Anders', 'permissions' => ['accounts.view']]);

        $this->assertSame(409, $r['status']);
        $this->assertSame('role_protected', $r['json']['code']);
        $this->assertSame($before, $this->roleByKey($key));
        $this->assertCount(0, $this->audit('role.updated'));
    }

    /** @return iterable<string,array{string}> */
    public static function bearbeitbareStandardrollen(): iterable
    {
        foreach (['lehrkraft', 'erziehungsberechtigte', 'sekretariat', 'schulleitung'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('bearbeitbareStandardrollen')]
    public function testRechteDerUebrigenStandardrollenSindAenderbarIhrMfaMerkmalNicht(string $key): void
    {
        $role = $this->roleByKey($key);

        $ok = $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['permissions' => ['accounts.view'], 'requires_mfa' => $role['requires_mfa']]);
        $this->assertSame(200, $ok['status'], $ok['body']);
        $this->assertSame(['accounts.view'], $ok['json']['role']['permissions']);

        $mfa = $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['requires_mfa' => !$role['requires_mfa']]);
        $this->assertSame(409, $mfa['status']);
        $this->assertSame('role_protected', $mfa['json']['code']);
        $this->assertSame($role['requires_mfa'], $this->roleByKey($key)['requires_mfa']);
    }

    // --- P2 beim Bearbeiten ---

    public function testOhneWildcardLassenSichNurRechteHinzufuegenDieManSelbstBesitzt(): void
    {
        $role = $this->create();

        $r = $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['permissions' => ['accounts.view', 'accounts.invite']], self::MANAGER);

        $this->assertSame(403, $r['status']);
        $this->assertSame('permission_not_held', $r['json']['code']);
        $this->assertSame(['accounts.view'], $this->roleByKey($role['key'])['permissions']);
    }

    public function testEineRolleDieManSelbstInnehatLaesstSichNichtErweiternAberVerkleinern(): void
    {
        $role = $this->create(['permissions' => ['accounts.view']]);
        $this->app->assignRole(self::MANAGER, $role['key']);

        // accounts.view besitzt der Verwalter schon ueber seinen Einzelrechte-Eintrag; trotzdem: eigene Rolle.
        $extend = $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['permissions' => ['accounts.view', 'roles.manage']], self::MANAGER);
        $this->assertSame(403, $extend['status']);
        $this->assertSame('self_escalation', $extend['json']['code']);

        $shrink = $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['permissions' => []], self::MANAGER);
        $this->assertSame(200, $shrink['status'], $shrink['body']);
        $this->assertSame([], $shrink['json']['role']['permissions']);
    }

    public function testEntfernenIstOhneBesitzDerRechteErlaubt(): void
    {
        $role = $this->create(['permissions' => ['accounts.view', 'accounts.invite']]);

        $r = $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['permissions' => ['accounts.view']], self::MANAGER);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['accounts.view'], $r['json']['role']['permissions']);
    }

    public function testWildcardKontoIstVonP2AusgenommenAuchBeiEigenerRolle(): void
    {
        $role = $this->create(['permissions' => ['accounts.view']]);
        $this->app->assignRole(self::ADMIN, $role['key']);

        $r = $this->call('POST', '/api/roles/' . $role['id'] . '/update', ['permissions' => ['accounts.view', 'roles.manage']]);

        $this->assertSame(200, $r['status'], $r['body']);
    }

    // --- Loeschen ---

    public function testEigeneRolleLoeschenEntferntSieUndSchreibtAudit(): void
    {
        $role = $this->create();

        $r = $this->call('POST', '/api/roles/' . $role['id'] . '/delete');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertNotContains($role['key'], array_column($this->call('GET', '/api/roles')['json']['roles'], 'key'));
        $entries = $this->audit('role.deleted');
        $this->assertCount(1, $entries);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame($role['id'], $entries[0]['object_id']);
        $this->assertSame(['role' => $role['key'], 'name' => 'Fachbereichsleitung'], $entries[0]['ctx']);
        $this->assertSame(0, (int) $this->app->pdo()->query("SELECT COUNT(*) FROM `role_settings` WHERE `role_id` = '{$role['id']}'")->fetchColumn());
    }

    public function testZugewieseneRolleLaesstSichNichtLoeschen(): void
    {
        $role = $this->create();
        $this->app->createAccount(self::MEMBER, 'active');
        $this->app->assignRole(self::MEMBER, $role['key']);

        $r = $this->call('POST', '/api/roles/' . $role['id'] . '/delete');

        $this->assertSame(409, $r['status']);
        $this->assertSame('role_in_use', $r['json']['code']);
        $this->assertSame(1, $this->roleByKey($role['key'])['account_count']);
        $this->assertCount(0, $this->audit('role.deleted'));
    }

    /** @return iterable<string,array{string}> */
    public static function standardrollen(): iterable
    {
        foreach (['schuladmin', 'schueler', 'lehrkraft', 'erziehungsberechtigte', 'sekretariat', 'schulleitung'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('standardrollen')]
    public function testKeineStandardrolleIstLoeschbar(string $key): void
    {
        $role = $this->roleByKey($key);

        $r = $this->call('POST', '/api/roles/' . $role['id'] . '/delete');

        $this->assertSame(409, $r['status']);
        $this->assertSame('role_protected', $r['json']['code']);
        $this->assertSame($role, $this->roleByKey($key));
    }

    /** Eine Rolle ohne Zeile in `role_settings` (darf es nicht geben); gibt ihre ID zurueck. */
    private function roleWithoutSettings(): string
    {
        $id = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $this->app->pdo()->exec("INSERT INTO `permission_roles` (`id`, `role_key`, `name`, `is_system`) VALUES ('$id', 'r_ohne', 'Ohne Merkmale', 0)");
        return $id;
    }

    public function testRolleOhneMerkmaleWirdNichtStillschweigendAlsEigeneRolleMitMfaBehandelt(): void
    {
        $id = $this->roleWithoutSettings();

        $update = $this->call('POST', "/api/roles/$id/update", ['name' => 'Umbenannt', 'requires_mfa' => false]);
        $delete = $this->call('POST', "/api/roles/$id/delete");

        $this->assertSame(500, $update['status']);
        $this->assertSame(500, $delete['status']);
        $this->assertSame('Ohne Merkmale', $this->app->pdo()->query("SELECT `name` FROM `permission_roles` WHERE `id` = '$id'")->fetchColumn(), 'unveraendert');
        $this->assertSame([], $this->audit('role.updated'));
        $this->assertSame([], $this->audit('role.deleted'));
        $this->assertSame(0, (int) $this->app->pdo()->query("SELECT COUNT(*) FROM `role_settings` WHERE `role_id` = '$id'")->fetchColumn(), 'es wird auch nichts nachgetragen');
    }

    public function testRollenlisteMeldetRolleOhneMerkmaleAlsFehlerStattSieZuRaten(): void
    {
        $this->roleWithoutSettings();

        $this->assertSame(500, $this->call('GET', '/api/roles')['status']);
    }
}
