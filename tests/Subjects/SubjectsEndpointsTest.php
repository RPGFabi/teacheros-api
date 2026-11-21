<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Subjects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Faecher anlegen und auflisten (Ticket #62) ueber HTTP gegen php-cgi + Test-DB:
 * GET /api/subjects und GET /api/subjects/selectable (`subjects.view`), POST /api/subjects (`subjects.manage`).
 */
final class SubjectsEndpointsTest extends TestCase
{
    private const ADMIN = '62626262-0000-4562-8562-000000000001';
    private const VIEWER = '62626262-0000-4562-8562-000000000002';
    private const MANAGER = '62626262-0000-4562-8562-000000000003';
    private const NOBODY = '62626262-0000-4562-8562-000000000004';
    private const URI = '/api/subjects';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->resetSubjects();
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        $this->app->createAccount(self::VIEWER, 'active');
        $this->app->grantPermission(self::VIEWER, 'subjects.view');
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'subjects.manage');
        $this->app->createAccount(self::NOBODY, 'active');
    }

    private function headers(string $sub): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)];
    }

    private function list(string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->app->request('GET', $uri, $this->headers($sub));
    }

    private function create(array $body, string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->app->request('POST', $uri, $this->headers($sub), $body);
    }

    private function createSubject(string $code, string $name = 'Fach'): array
    {
        return $this->create(['code' => $code, 'name' => $name]);
    }

    // --- Rechte ---

    public function testKatalogFuehrtBeideFachRechteInDerGruppeStammdaten(): void
    {
        $r = $this->app->request('GET', '/api/permissions', $this->headers(self::ADMIN));

        $byKey = array_column($r['json']['permissions'], null, 'key');
        $this->assertSame(['key' => 'subjects.view', 'name' => 'Fächer ansehen', 'group' => 'Stammdaten'], $byKey['subjects.view']);
        $this->assertSame(['key' => 'subjects.manage', 'name' => 'Fächer verwalten', 'group' => 'Stammdaten'], $byKey['subjects.manage']);
    }

    public function testKeineStandardrolleHatEinFachRechtVoreingestellt(): void
    {
        $roles = $this->app->request('GET', '/api/roles', $this->headers(self::ADMIN))['json']['roles'];

        foreach ($roles as $role) {
            if ($role['key'] === 'schuladmin') {
                continue;
            }
            $this->assertNotContains('subjects.view', $role['permissions'], $role['key']);
            $this->assertNotContains('subjects.manage', $role['permissions'], $role['key']);
        }
    }

    public function testSchuladminHatBeideRechteUeberDieWildcard(): void
    {
        $me = $this->app->request('GET', '/api/me', $this->headers(self::ADMIN))['json'];

        $this->assertContains('subjects.view', $me['permissions']);
        $this->assertContains('subjects.manage', $me['permissions']);
    }

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('GET', self::URI)['status']);
        $this->assertSame(401, $this->app->request('GET', self::URI . '/selectable')['status']);
        $this->assertSame(401, $this->app->request('POST', self::URI, [], ['code' => 'Ev', 'name' => 'Evangelische Religion'])['status']);
    }

    public function testLesenBrauchtSubjectsViewSonst403Forbidden(): void
    {
        foreach ([self::NOBODY, self::MANAGER] as $sub) {
            foreach ([$this->list($sub), $this->list($sub, self::URI . '/selectable')] as $r) {
                $this->assertSame(403, $r['status'], $sub);
                $this->assertSame('forbidden', $r['json']['code'], $sub);
            }
        }
        $this->assertSame(200, $this->list(self::VIEWER)['status']);
        $this->assertSame(200, $this->list(self::VIEWER, self::URI . '/selectable')['status']);
    }

    public function testAnlegenBrauchtSubjectsManageSonst403Forbidden(): void
    {
        foreach ([self::NOBODY, self::VIEWER] as $sub) {
            $r = $this->create(['code' => 'Ev', 'name' => 'Evangelische Religion'], $sub);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('forbidden', $r['json']['code'], $sub);
        }
        $this->assertSame([], $this->app->subjects(), 'ohne Recht wird nichts angelegt');
        $this->assertSame(201, $this->create(['code' => 'Ev', 'name' => 'Evangelische Religion'], self::MANAGER)['status']);
    }

    public function testModulpruefungLaeuftVorDerRechtepruefung(): void
    {
        // Dieselben Routen, aber unter dem Modul `observe` (ohne Freigabe) eingehaengt: tests/Support/module-probe-routes.php.
        $this->app->resetModules();
        $probe = '/api/test/observe-subjects';

        $admin = $this->list(self::ADMIN, $probe);
        $this->assertSame(403, $admin['status']);
        $this->assertSame('module_disabled', $admin['json']['code']);

        $nobody = $this->create(['code' => 'Ev', 'name' => 'Evangelische Religion'], self::NOBODY, $probe);
        $this->assertSame(403, $nobody['status']);
        $this->assertSame('module_disabled', $nobody['json']['code'], 'der Grund ist das Modul, nicht das fehlende Recht');
        $this->assertSame('module_disabled', $this->list(self::NOBODY, $probe . '/selectable')['json']['code']);
        $this->assertSame([], $this->app->subjects());

        $this->app->insertEntitlement('observe', true, gmdate('Y-m-d H:i:s.u', time() - 3600));
        $this->assertSame('forbidden', $this->list(self::NOBODY, $probe)['json']['code'], 'eine Freigabe gewaehrt kein Recht');
        $this->assertSame(200, $this->list(self::ADMIN, $probe)['status']);
    }

    public function testRoutenUnterCoreBrauchenKeineModulfreigabe(): void
    {
        $this->app->resetModules();

        $this->assertSame(200, $this->list()['status']);
    }

    // --- Anlegen ---

    public function testAnlegenLiefert201MitKennungKuerzelUndBezeichnung(): void
    {
        $r = $this->create(['code' => 'Ev', 'name' => 'Evangelische Religion']);

        $this->assertSame(201, $r['status'], $r['body']);
        $subject = $r['json']['subject'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $subject['id']);
        $this->assertSame('Ev', $subject['code']);
        $this->assertSame('Evangelische Religion', $subject['name']);
        $this->assertSame(['id', 'code', 'name', 'archived'], array_keys($subject));
        $this->assertCount(1, $this->app->subjects());
    }

    public function testAnlegenSchreibtAuditEintragNurMitKuerzelUndBezeichnung(): void
    {
        $id = $this->create(['code' => 'Ev', 'name' => 'Evangelische Religion'])['json']['subject']['id'];

        $entries = array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'subject.created'));
        $this->assertCount(1, $entries);
        $this->assertSame('subject', $entries[0]['object_type']);
        $this->assertSame($id, $entries[0]['object_id']);
        $this->assertSame('account', $entries[0]['actor_type']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['code' => 'Ev', 'name' => 'Evangelische Religion'], json_decode($entries[0]['context'], true));
    }

    public function testKuerzelUndBezeichnungWerdenVorDemSpeichernGetrimmtUndSchreibungBleibt(): void
    {
        $r = $this->create(['code' => "\u{00A0} eV \u{00A0}", 'name' => "  Evangelische Religion\t"]);

        $this->assertSame('eV', $r['json']['subject']['code'], 'Schreibung bleibt wie eingegeben');
        $this->assertSame('Evangelische Religion', $r['json']['subject']['name']);
        $this->assertSame('eV', $this->app->subjects()[0]['code']);
    }

    public function testKuerzelUndBezeichnungWerdenNachNfcNormalisiert(): void
    {
        if (!class_exists(\Normalizer::class)) {
            $this->markTestSkipped('ext-intl fehlt: ohne sie wird nicht normalisiert (docs/workspace.md).');
        }

        $r = $this->create(['code' => "Ku\u{0308}n", 'name' => "Ku\u{0308}nste"]);

        $this->assertSame("K\u{00FC}n", $r['json']['subject']['code']);
        $this->assertSame("K\u{00FC}nste", $r['json']['subject']['name']);
        $this->assertSame('subject_code_taken', $this->create(['code' => "K\u{00FC}N", 'name' => 'Andere'])['json']['code'], 'zerlegt und zusammengesetzt ist dasselbe Kuerzel');
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function ungueltigeEingaben(): iterable
    {
        yield 'ohne Kuerzel' => [['name' => 'Deutsch']];
        yield 'leeres Kuerzel' => [['code' => '', 'name' => 'Deutsch']];
        yield 'Kuerzel nur Leerzeichen' => [['code' => '   ', 'name' => 'Deutsch']];
        yield 'Kuerzel nur unsichtbare Zeichen' => [['code' => "\u{200B}\u{FEFF}", 'name' => 'Deutsch']];
        yield 'Kuerzel 11 Zeichen' => [['code' => str_repeat('a', 11), 'name' => 'Deutsch']];
        yield 'Kuerzel 11 Umlaute' => [['code' => str_repeat('ä', 11), 'name' => 'Deutsch']];
        yield 'Kuerzel mit Leerzeichen innen' => [['code' => 'D E', 'name' => 'Deutsch']];
        yield 'Kuerzel mit geschuetztem Leerzeichen innen' => [['code' => "D\u{00A0}E", 'name' => 'Deutsch']];
        yield 'Kuerzel mit Punkt' => [['code' => 'D.', 'name' => 'Deutsch']];
        yield 'Kuerzel mit Schraegstrich' => [['code' => 'D/E', 'name' => 'Deutsch']];
        yield 'Kuerzel mit Emoji' => [['code' => "D\u{1F600}", 'name' => 'Deutsch']];
        yield 'Kuerzel keine Zeichenkette' => [['code' => 7, 'name' => 'Deutsch']];
        yield 'Kuerzel null' => [['code' => null, 'name' => 'Deutsch']];
        yield 'ohne Bezeichnung' => [['code' => 'D']];
        yield 'leere Bezeichnung' => [['code' => 'D', 'name' => '']];
        yield 'Bezeichnung nur Leerzeichen' => [['code' => 'D', 'name' => "  \u{00A0} "]];
        yield 'Bezeichnung 61 Zeichen' => [['code' => 'D', 'name' => str_repeat('a', 61)]];
        yield 'Bezeichnung 61 Umlaute' => [['code' => 'D', 'name' => str_repeat('ä', 61)]];
        yield 'Bezeichnung keine Zeichenkette' => [['code' => 'D', 'name' => 7]];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('ungueltigeEingaben')]
    public function testUngueltigeEingabeWird400AbgelehntOhneAuditEintrag(array $body): void
    {
        $r = $this->create($body);

        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertNotSame('', $r['json']['error']);
        $this->assertSame([], $this->app->subjects());
        $this->assertNotContains('subject.created', array_column($this->app->auditLog(), 'action'));
    }

    public function testGrenzwerteSindErlaubt(): void
    {
        foreach ([['a', 'a'], [str_repeat('a', 10), str_repeat('a', 60)], [str_repeat('ä', 10), str_repeat('ä', 60)], ['Ge-Wi_2', 'Geschichte'], ['D1', '1'], ['  Ph  ', ' Physik ']] as [$code, $name]) {
            $this->assertSame(201, $this->create(['code' => $code, 'name' => $name])['status'], "$code $name");
        }
    }

    public function testBezeichnungMussNichtEindeutigSein(): void
    {
        $this->assertSame(201, $this->createSubject('Ev', 'Religion')['status']);
        $this->assertSame(201, $this->createSubject('Ka', 'Religion')['status']);
        $this->assertSame(201, $this->createSubject('ev2', 'RELIGION')['status']);
    }

    // --- Eindeutigkeit ---

    /** @return iterable<string,array{string}> */
    public static function gleicheKuerzel(): iterable
    {
        yield 'identisch' => ['Ev'];
        yield 'andere Gross-/Kleinschreibung' => ['EV'];
        yield 'kleingeschrieben' => ['ev'];
        yield 'mit Leerzeichen drumherum' => ['  Ev '];
    }

    #[DataProvider('gleicheKuerzel')]
    public function testKuerzelIstEindeutigOhneGrossKleinschreibung409(string $code): void
    {
        $this->createSubject('Ev', 'Evangelische Religion');

        $r = $this->createSubject($code, 'Andere Bezeichnung');

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('subject_code_taken', $r['json']['code']);
        $this->assertNotSame('', $r['json']['error']);
        $this->assertCount(1, $this->app->subjects());
        $this->assertSame('Ev', $this->app->subjects()[0]['code'], 'die erste Schreibung bleibt');
        $this->assertCount(1, array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'subject.created'), 'kein Audit-Eintrag fuer die Ablehnung');
    }

    public function testUmlautIstEinAnderesZeichenAlsDerGrundbuchstabe(): void
    {
        $this->assertSame(201, $this->createSubject('Ku')['status']);

        $this->assertSame(201, $this->createSubject('Kü')['status']);
        $this->assertSame('subject_code_taken', $this->createSubject('KÜ')['json']['code'], 'Umlaute: Gross-/Kleinschreibung egal');
    }

    public function testZweiGleichzeitigeAnfragenMitGleichemKuerzelLegenNurEinFachAn(): void
    {
        $call = ['POST', self::URI, $this->headers(self::ADMIN), ['code' => 'Ev', 'name' => 'Evangelische Religion']];

        $responses = $this->app->requestConcurrently([$call, $call, $call]);

        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409, 409], $statuses);
        $this->assertCount(1, $this->app->subjects());
        $this->assertCount(1, array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'subject.created'));
    }

    // --- Lesen ---

    public function testListeIstLeerOhneFaecher(): void
    {
        $r = $this->list(self::VIEWER);

        $this->assertSame(200, $r['status']);
        $this->assertSame(['subjects' => []], $r['json']);
    }

    public function testListeSortiertNatuerlichNachKuerzelOhneGrossKleinschreibung(): void
    {
        foreach (['D10', 'd2', 'Ma', 'D1', 'En', 'Bi', 'ma2', 'Ev'] as $code) {
            $this->assertSame(201, $this->createSubject($code)['status'], $code);
        }

        $subjects = $this->list(self::VIEWER)['json']['subjects'];

        $this->assertSame(['Bi', 'D1', 'd2', 'D10', 'En', 'Ev', 'Ma', 'ma2'], array_column($subjects, 'code'));
        $this->assertSame(['id', 'code', 'name', 'archived'], array_keys($subjects[0]));
    }

    public function testWaehlbareFaecherSindAlleAktivenFaecherInDerSortierungDerListe(): void
    {
        $this->createSubject('Ma', 'Mathematik');
        $this->createSubject('Bi', 'Biologie');

        $list = $this->list(self::VIEWER)['json'];
        $selectable = $this->list(self::VIEWER, self::URI . '/selectable')['json'];

        $this->assertSame(['Bi', 'Ma'], array_column($selectable['subjects'], 'code'));
        $this->assertSame($list, $selectable);
    }

    // --- Migration ---

    public function testMigrationIstGefahrlosWiederholbar(): void
    {
        $this->createSubject('Ev');

        $this->app->rerunMigrations('0019_subjects.php');

        $this->assertCount(1, $this->app->subjects(), 'bestehende Faecher bleiben erhalten');
    }
}
