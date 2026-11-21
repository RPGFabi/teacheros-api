<?php

declare(strict_types=1);

namespace TeacherOS\Tests\TeachingGroups;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Fachgruppen anlegen und auflisten (Ticket #64) ueber HTTP gegen php-cgi + Test-DB:
 * GET /api/teaching-groups?school_year_id=, GET /api/teaching-groups/:id, GET .../school-years und .../choices
 * (`teaching_groups.view`), POST /api/teaching-groups (`teaching_groups.manage`).
 */
final class TeachingGroupsEndpointsTest extends TestCase
{
    private const ADMIN = '64646464-0000-4564-8564-000000000001';
    private const VIEWER = '64646464-0000-4564-8564-000000000002';
    private const MANAGER = '64646464-0000-4564-8564-000000000003';
    private const NOBODY = '64646464-0000-4564-8564-000000000004';
    private const URI = '/api/teaching-groups';

    private HttpTestApp $app;
    private string $year;
    private string $subject;
    private string $class7a;
    private string $class7b;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->resetSchoolYears();
        $this->app->resetSubjects();
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        $this->app->createAccount(self::VIEWER, 'active');
        $this->app->grantPermission(self::VIEWER, 'teaching_groups.view');
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'teaching_groups.manage');
        $this->app->createAccount(self::NOBODY, 'active');
        $this->year = $this->createYear('2090-09-01', '2091-07-31');
        $this->subject = $this->createSubject('Ma', 'Mathematik');
        $this->class7a = $this->createClass(7, '7a');
        $this->class7b = $this->createClass(7, '7b');
    }

    private function headers(string $sub): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)];
    }

    private function createYear(string $start, string $end): string
    {
        $r = $this->app->request('POST', '/api/school-years', $this->headers(self::ADMIN), ['starts_on' => $start, 'ends_on' => $end]);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json']['school_year']['id'];
    }

    private function createSubject(string $code, string $name): string
    {
        $r = $this->app->request('POST', '/api/subjects', $this->headers(self::ADMIN), ['code' => $code, 'name' => $name]);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json']['subject']['id'];
    }

    private function createClass(int $grade, string $name, ?string $yearId = null): string
    {
        $r = $this->app->request('POST', '/api/classes', $this->headers(self::ADMIN), ['school_year_id' => $yearId ?? $this->year, 'grade_level' => $grade, 'name' => $name]);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json']['class']['id'];
    }

    private function get(string $uri, string $sub = self::ADMIN): array
    {
        return $this->app->request('GET', $uri, $this->headers($sub));
    }

    private function list(?string $yearId = null, string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->get($uri . ($yearId === null ? '' : '?school_year_id=' . rawurlencode($yearId)), $sub);
    }

    private function create(array $body, string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->app->request('POST', $uri, $this->headers($sub), $body);
    }

    /** @param list<string>|null $classIds */
    private function createGroup(string $name = 'Ma 7', ?array $classIds = null, ?string $subjectId = null, ?string $yearId = null): array
    {
        return $this->create(['school_year_id' => $yearId ?? $this->year, 'subject_id' => $subjectId ?? $this->subject, 'name' => $name, 'class_ids' => $classIds ?? [$this->class7a]]);
    }

    // --- Rechte ---

    public function testKatalogFuehrtBeideFachgruppenRechteInDerGruppeStammdaten(): void
    {
        $r = $this->app->request('GET', '/api/permissions', $this->headers(self::ADMIN));

        $byKey = array_column($r['json']['permissions'], null, 'key');
        $this->assertSame(['key' => 'teaching_groups.view', 'name' => 'Fachgruppen ansehen', 'group' => 'Stammdaten'], $byKey['teaching_groups.view']);
        $this->assertSame(['key' => 'teaching_groups.manage', 'name' => 'Fachgruppen verwalten', 'group' => 'Stammdaten'], $byKey['teaching_groups.manage']);
    }

    public function testKeineStandardrolleHatEinFachgruppenRechtVoreingestellt(): void
    {
        $roles = $this->app->request('GET', '/api/roles', $this->headers(self::ADMIN))['json']['roles'];

        foreach ($roles as $role) {
            if ($role['key'] === 'schuladmin') {
                continue;
            }
            $this->assertNotContains('teaching_groups.view', $role['permissions'], $role['key']);
            $this->assertNotContains('teaching_groups.manage', $role['permissions'], $role['key']);
        }
    }

    public function testSchuladminHatBeideRechteUeberDieWildcard(): void
    {
        $me = $this->app->request('GET', '/api/me', $this->headers(self::ADMIN))['json'];

        $this->assertContains('teaching_groups.view', $me['permissions']);
        $this->assertContains('teaching_groups.manage', $me['permissions']);
    }

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('GET', self::URI . '?school_year_id=' . $this->year)['status']);
        $this->assertSame(401, $this->app->request('GET', self::URI . '/school-years')['status']);
        $this->assertSame(401, $this->app->request('GET', self::URI . '/choices?school_year_id=' . $this->year)['status']);
        $this->assertSame(401, $this->app->request('GET', self::URI . '/' . $this->year)['status']);
        $this->assertSame(401, $this->app->request('POST', self::URI, [], [])['status']);
    }

    public function testLesenBrauchtTeachingGroupsViewSonst403Forbidden(): void
    {
        $id = $this->createGroup()['json']['teaching_group']['id'];
        $reads = [
            fn (string $sub): array => $this->list($this->year, $sub),
            fn (string $sub): array => $this->get(self::URI . '/school-years', $sub),
            fn (string $sub): array => $this->get(self::URI . '/choices?school_year_id=' . $this->year, $sub),
            fn (string $sub): array => $this->get(self::URI . '/' . $id, $sub),
        ];

        foreach ($reads as $i => $read) {
            foreach ([self::NOBODY, self::MANAGER] as $sub) {
                $r = $read($sub);
                $this->assertSame(403, $r['status'], "$i $sub");
                $this->assertSame('forbidden', $r['json']['code'], "$i $sub");
            }
            $this->assertSame(200, $read(self::VIEWER)['status'], (string) $i);
        }
    }

    public function testAnlegenBrauchtTeachingGroupsManageSonst403Forbidden(): void
    {
        foreach ([self::NOBODY, self::VIEWER] as $sub) {
            $r = $this->create(['school_year_id' => $this->year, 'subject_id' => $this->subject, 'name' => 'Ma 7', 'class_ids' => [$this->class7a]], $sub);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('forbidden', $r['json']['code'], $sub);
        }
        $this->assertSame([], $this->app->teachingGroups(), 'ohne Recht wird nichts angelegt');
        $this->assertSame(201, $this->create(['school_year_id' => $this->year, 'subject_id' => $this->subject, 'name' => 'Ma 7', 'class_ids' => [$this->class7a]], self::MANAGER)['status']);
    }

    public function testModulpruefungLaeuftVorDerRechtepruefung(): void
    {
        // Dieselben Routen, aber unter dem Modul `observe` (ohne Freigabe) eingehaengt: tests/Support/module-probe-routes.php.
        $this->app->resetModules();
        $probe = '/api/test/observe-teaching-groups';

        $admin = $this->list($this->year, self::ADMIN, $probe);
        $this->assertSame(403, $admin['status']);
        $this->assertSame('module_disabled', $admin['json']['code']);

        $nobody = $this->create(['school_year_id' => $this->year, 'subject_id' => $this->subject, 'name' => 'Ma 7', 'class_ids' => [$this->class7a]], self::NOBODY, $probe);
        $this->assertSame(403, $nobody['status']);
        $this->assertSame('module_disabled', $nobody['json']['code'], 'der Grund ist das Modul, nicht das fehlende Recht');
        foreach (['/school-years', '/choices?school_year_id=' . $this->year, '/' . $this->year] as $suffix) {
            $this->assertSame('module_disabled', $this->get($probe . $suffix, self::NOBODY)['json']['code'], $suffix);
        }
        $this->assertSame([], $this->app->teachingGroups());

        $this->app->insertEntitlement('observe', true, gmdate('Y-m-d H:i:s.u', time() - 3600));
        $this->assertSame('forbidden', $this->list($this->year, self::NOBODY, $probe)['json']['code'], 'eine Freigabe gewaehrt kein Recht');
        $this->assertSame(200, $this->list($this->year, self::ADMIN, $probe)['status']);
    }

    public function testRoutenUnterCoreBrauchenKeineModulfreigabe(): void
    {
        $this->app->resetModules();

        $this->assertSame(200, $this->list($this->year)['status']);
    }

    // --- Anlegen ---

    public function testAnlegenLiefert201MitFachKlassenUndSchuljahr(): void
    {
        $r = $this->createGroup('Ma 7', [$this->class7b, $this->class7a]);

        $this->assertSame(201, $r['status'], $r['body']);
        $group = $r['json']['teaching_group'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $group['id']);
        $this->assertSame(['id', 'name', 'school_year', 'subject', 'classes'], array_keys($group));
        $this->assertSame('Ma 7', $group['name']);
        $this->assertSame(['id' => $this->year, 'name' => '2090/91'], $group['school_year']);
        $this->assertSame(['id' => $this->subject, 'code' => 'Ma', 'name' => 'Mathematik', 'archived' => false], $group['subject']);
        $this->assertSame(['7a', '7b'], array_column($group['classes'], 'name'), 'Klassen nach Stufe und natuerlicher Bezeichnung');
        $this->assertSame(['id' => $this->class7a, 'grade_level' => 7, 'name' => '7a', 'archived' => false, 'school_year' => ['id' => $this->year, 'name' => '2090/91']], $group['classes'][0]);
        $this->assertCount(1, $this->app->teachingGroups());
        $this->assertCount(2, $this->app->teachingGroupClasses());
    }

    public function testAnlegenSchreibtAuditEintragNurMitBezeichnung(): void
    {
        $id = $this->createGroup('AES 1')['json']['teaching_group']['id'];

        $entries = array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'teaching_group.created'));
        $this->assertCount(1, $entries);
        $this->assertSame('teaching_group', $entries[0]['object_type']);
        $this->assertSame($id, $entries[0]['object_id']);
        $this->assertSame('account', $entries[0]['actor_type']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['name' => 'AES 1'], json_decode($entries[0]['context'], true));
    }

    public function testEineFachgruppeKannKlassenMehrererStufenUmfassen(): void
    {
        $class8 = $this->createClass(8, '8a');

        $r = $this->createGroup('AES 1', [$this->class7a, $class8]);

        $this->assertSame(201, $r['status'], $r['body']);
        $this->assertSame(['7a', '8a'], array_column($r['json']['teaching_group']['classes'], 'name'));
    }

    public function testDieselbeKlasseDarfInMehrerenFachgruppenSein(): void
    {
        $this->assertSame(201, $this->createGroup('Ma 7')['status']);
        $this->assertSame(201, $this->createGroup('Ma 7 Foerder')['status']);
    }

    public function testDoppelteKlassenKennungenWerdenZusammengefasst(): void
    {
        $r = $this->createGroup('Ma 7', [$this->class7a, $this->class7a]);

        $this->assertSame(201, $r['status'], $r['body']);
        $this->assertCount(1, $r['json']['teaching_group']['classes']);
        $this->assertCount(1, $this->app->teachingGroupClasses());
    }

    public function testBezeichnungWirdGetrimmtUndNachNfcNormalisiert(): void
    {
        $r = $this->createGroup("\u{00A0} Ku\u{0308}nste \t");

        $expected = class_exists(\Normalizer::class) ? "K\u{00FC}nste" : "Ku\u{0308}nste";
        $this->assertSame($expected, $r['json']['teaching_group']['name']);
        if (class_exists(\Normalizer::class)) {
            $this->assertSame('teaching_group_name_taken', $this->createGroup("K\u{00FC}NSTE")['json']['code'], 'zerlegt und zusammengesetzt ist dieselbe Bezeichnung');
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function ungueltigeEingaben(): iterable
    {
        yield 'ohne Bezeichnung' => [['name' => null]];
        yield 'leere Bezeichnung' => [['name' => '']];
        yield 'Bezeichnung nur Leerraum' => [['name' => "  \u{00A0}\u{200B} "]];
        yield 'Bezeichnung 41 Zeichen' => [['name' => str_repeat('a', 41)]];
        yield 'Bezeichnung 41 Umlaute' => [['name' => str_repeat('ä', 41)]];
        yield 'Bezeichnung keine Zeichenkette' => [['name' => 7]];
        yield 'ohne Schuljahr' => [['school_year_id' => null]];
        yield 'leeres Schuljahr' => [['school_year_id' => '']];
        yield 'Schuljahr keine Zeichenkette' => [['school_year_id' => 5]];
        yield 'ohne Fach' => [['subject_id' => null]];
        yield 'leeres Fach' => [['subject_id' => '']];
        yield 'Fach keine Zeichenkette' => [['subject_id' => ['x']]];
        yield 'ohne Klassen' => [['class_ids' => null]];
        yield 'leere Klassenliste' => [['class_ids' => []]];
        yield 'Klassen keine Liste' => [['class_ids' => 'abc']];
        yield 'Klassenkennung keine Zeichenkette' => [['class_ids' => [7]]];
        yield 'leere Klassenkennung' => [['class_ids' => ['']]];
    }

    /** @param array<string,mixed> $override */
    #[DataProvider('ungueltigeEingaben')]
    public function testUngueltigeEingabeWird400AbgelehntOhneAuditEintrag(array $override): void
    {
        $body = array_replace(['school_year_id' => $this->year, 'subject_id' => $this->subject, 'name' => 'Ma 7', 'class_ids' => [$this->class7a]], $override);

        $r = $this->create($body);

        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertNotSame('', $r['json']['error']);
        $this->assertSame([], $this->app->teachingGroups());
        $this->assertNotContains('teaching_group.created', array_column($this->app->auditLog(), 'action'));
    }

    public function testBezeichnungGrenzwerteSindErlaubt(): void
    {
        foreach (['a', str_repeat('a', 40), str_repeat('ä', 40), ' AES 1 '] as $name) {
            $this->assertSame(201, $this->createGroup($name)['status'], $name);
        }
    }

    public function testUnbekanntesSchuljahrIst404(): void
    {
        $r = $this->createGroup('Ma 7', null, null, '00000000-0000-4000-8000-000000000000');

        $this->assertSame(404, $r['status']);
        $this->assertSame('school_year_not_found', $r['json']['code']);
        $this->assertSame([], $this->app->teachingGroups());
    }

    public function testUnbekanntesFachIstAbgelehntMitEigenemCode(): void
    {
        $r = $this->createGroup('Ma 7', null, '00000000-0000-4000-8000-000000000000');

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('subject_not_selectable', $r['json']['code']);
        $this->assertNotSame('', $r['json']['error']);
        $this->assertSame([], $this->app->teachingGroups());
    }

    public function testUnbekannteKlasseIstAbgelehntMitEigenemCode(): void
    {
        $r = $this->createGroup('Ma 7', [$this->class7a, '00000000-0000-4000-8000-000000000000']);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('class_not_selectable', $r['json']['code']);
        $this->assertSame([], $this->app->teachingGroups());
        $this->assertSame([], $this->app->teachingGroupClasses());
    }

    public function testArchivierteKlasseIstNichtWaehlbar(): void
    {
        $this->app->request('POST', '/api/classes/' . $this->class7b . '/archive', $this->headers(self::ADMIN));

        $r = $this->createGroup('Ma 7', [$this->class7a, $this->class7b]);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('class_not_selectable', $r['json']['code']);
        $this->assertSame([], $this->app->teachingGroups());
    }

    public function testKlasseAusAnderemSchuljahrIstNichtWaehlbar(): void
    {
        $otherYear = $this->createYear('2091-09-01', '2092-07-31');
        $otherClass = $this->createClass(7, '7a', $otherYear);

        $r = $this->createGroup('Ma 7', [$this->class7a, $otherClass]);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('class_not_selectable', $r['json']['code']);
        $this->assertSame([], $this->app->teachingGroups());
    }

    // --- Eindeutigkeit ---

    /** @return iterable<string,array{string}> */
    public static function gleicheBezeichnungen(): iterable
    {
        yield 'identisch' => ['AES 1'];
        yield 'andere Gross-/Kleinschreibung' => ['aes 1'];
        yield 'mit Leerraum drumherum' => ["  AES 1\u{00A0}"];
    }

    #[DataProvider('gleicheBezeichnungen')]
    public function testBezeichnungIstImSchuljahrEindeutigOhneGrossKleinschreibung409(string $name): void
    {
        $this->createGroup('AES 1');

        $r = $this->createGroup($name, [$this->class7b], $this->createSubject('En', 'Englisch'));

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('teaching_group_name_taken', $r['json']['code']);
        $this->assertNotSame('', $r['json']['error']);
        $this->assertCount(1, $this->app->teachingGroups());
        $this->assertSame([$this->class7a], array_column($this->app->teachingGroupClasses(), 'class_id'), 'keine Klassen der abgelehnten Fachgruppe');
        $this->assertCount(1, array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'teaching_group.created'), 'kein Audit-Eintrag fuer die Ablehnung');
    }

    public function testGleicheBezeichnungInAnderemSchuljahrIstErlaubt(): void
    {
        $this->createGroup('AES 1');
        $otherYear = $this->createYear('2091-09-01', '2092-07-31');
        $otherClass = $this->createClass(7, '7a', $otherYear);

        $this->assertSame(201, $this->createGroup('AES 1', [$otherClass], null, $otherYear)['status']);
    }

    public function testUmlautIstEinAnderesZeichenAlsDerGrundbuchstabe(): void
    {
        $this->assertSame(201, $this->createGroup('Kurs U')['status']);

        $this->assertSame(201, $this->createGroup('Kurs Ü')['status']);
        $this->assertSame('teaching_group_name_taken', $this->createGroup('KURS ü')['json']['code']);
    }

    public function testZweiGleichzeitigeAnfragenMitGleicherBezeichnungLegenNurEineFachgruppeAn(): void
    {
        $call = ['POST', self::URI, $this->headers(self::ADMIN), ['school_year_id' => $this->year, 'subject_id' => $this->subject, 'name' => 'AES 1', 'class_ids' => [$this->class7a, $this->class7b]]];

        $responses = $this->app->requestConcurrently([$call, $call, $call]);

        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409, 409], $statuses);
        $this->assertCount(1, $this->app->teachingGroups());
        $this->assertCount(2, $this->app->teachingGroupClasses());
        $this->assertCount(1, array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'teaching_group.created'));
    }

    // --- Lesen ---

    public function testListeBrauchtDasSchuljahr(): void
    {
        $r = $this->list(null, self::VIEWER);

        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertSame('school_year_not_found', $this->list('00000000-0000-4000-8000-000000000000', self::VIEWER)['json']['code']);
    }

    public function testListeIstLeerOhneFachgruppen(): void
    {
        $r = $this->list($this->year, self::VIEWER);

        $this->assertSame(200, $r['status']);
        $this->assertSame(['teaching_groups' => []], $r['json']);
    }

    public function testListeZeigtNurDasGewaehlteSchuljahrSortiertNachFachkuerzelDannNatuerlichNachBezeichnung(): void
    {
        $de = $this->createSubject('De', 'Deutsch');
        $en = $this->createSubject('En', 'Englisch');
        $this->createGroup('Kurs 10', null, $this->subject);
        $this->createGroup('Kurs 2', null, $this->subject);
        $this->createGroup('Kurs 1', null, $this->subject);
        $this->createGroup('B-Kurs', null, $en);
        $this->createGroup('a-kurs', null, $en);
        $this->createGroup('Zeta', null, $de);
        $otherYear = $this->createYear('2091-09-01', '2092-07-31');
        $this->createGroup('Anderes Jahr', [$this->createClass(7, '7a', $otherYear)], $de, $otherYear);

        $groups = $this->list($this->year, self::VIEWER)['json']['teaching_groups'];

        $this->assertSame(['Zeta', 'a-kurs', 'B-Kurs', 'Kurs 1', 'Kurs 2', 'Kurs 10'], array_column($groups, 'name'));
        $this->assertSame(['De', 'En', 'En', 'Ma', 'Ma', 'Ma'], array_column(array_column($groups, 'subject'), 'code'));
        $this->assertSame(['id', 'name', 'school_year', 'subject', 'classes'], array_keys($groups[0]));
    }

    public function testListeZeigtJeFachgruppeIhreKlassen(): void
    {
        $class8 = $this->createClass(8, '8a');
        $this->createGroup('A', [$this->class7b, $class8, $this->class7a]);
        $this->createGroup('B', [$class8], $this->createSubject('Zz', 'Zett'));

        $groups = $this->list($this->year, self::VIEWER)['json']['teaching_groups'];

        $this->assertSame(['7a', '7b', '8a'], array_column($groups[0]['classes'], 'name'));
        $this->assertSame(['8a'], array_column($groups[1]['classes'], 'name'));
    }

    public function testEineSpaeterArchivierteKlasseBleibtInDerFachgruppeUndIstMarkiert(): void
    {
        $id = $this->createGroup('A', [$this->class7a, $this->class7b])['json']['teaching_group']['id'];
        $this->app->request('POST', '/api/classes/' . $this->class7b . '/archive', $this->headers(self::ADMIN));

        $group = $this->get(self::URI . '/' . $id, self::VIEWER)['json']['teaching_group'];

        $this->assertSame(['7a', '7b'], array_column($group['classes'], 'name'));
        $this->assertSame([false, true], array_column($group['classes'], 'archived'));
        $this->assertSame($group, $this->list($this->year, self::VIEWER)['json']['teaching_groups'][0]);
    }

    public function testDetailLiefertDieFachgruppeWieDasAnlegen(): void
    {
        $created = $this->createGroup('Ma 7', [$this->class7b, $this->class7a])['json']['teaching_group'];

        $r = $this->get(self::URI . '/' . $created['id'], self::VIEWER);

        $this->assertSame(200, $r['status']);
        $this->assertSame(['teaching_group' => $created], $r['json']);
    }

    public function testDetailEinerUnbekanntenFachgruppeIst404(): void
    {
        $r = $this->get(self::URI . '/00000000-0000-4000-8000-000000000000', self::VIEWER);

        $this->assertSame(404, $r['status']);
        $this->assertSame('teaching_group_not_found', $r['json']['code']);
    }

    public function testSchuljahrAuswahlListetAlleSchuljahreMitStatusOhneSchuljahrRecht(): void
    {
        $this->createYear('2089-09-01', '2090-07-31');

        $r = $this->get(self::URI . '/school-years', self::VIEWER);

        $this->assertSame(200, $r['status']);
        $years = $r['json']['school_years'];
        $this->assertSame(['2089/90', '2090/91'], array_column($years, 'name'), 'nach Beginn');
        $this->assertSame(['id', 'name', 'starts_on', 'ends_on', 'status'], array_keys($years[0]));
    }

    public function testAuswahlFuerDasFormularLiefertWaehlbareFaecherUndKlassenDesSchuljahres(): void
    {
        $this->createSubject('Bi', 'Biologie');
        $this->app->request('POST', '/api/classes/' . $this->class7b . '/archive', $this->headers(self::ADMIN));
        $otherYear = $this->createYear('2091-09-01', '2092-07-31');
        $this->createClass(9, '9a', $otherYear);

        $r = $this->get(self::URI . '/choices?school_year_id=' . $this->year, self::VIEWER);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['subjects', 'classes'], array_keys($r['json']));
        $this->assertSame(['Bi', 'Ma'], array_column($r['json']['subjects'], 'code'));
        $this->assertSame(['7a'], array_column($r['json']['classes'], 'name'), 'ohne archivierte und ohne andere Schuljahre');
        $this->assertSame(['id', 'code', 'name', 'archived'], array_keys($r['json']['subjects'][0]));
    }

    public function testAuswahlFuerDasFormularBrauchtEinBekanntesSchuljahr(): void
    {
        $this->assertSame(400, $this->get(self::URI . '/choices', self::VIEWER)['status']);
        $this->assertSame('school_year_not_found', $this->get(self::URI . '/choices?school_year_id=00000000-0000-4000-8000-000000000000', self::VIEWER)['json']['code']);
    }

    // --- Migration ---

    public function testMigrationIstGefahrlosWiederholbar(): void
    {
        $this->createGroup('Ma 7');

        $this->app->rerunMigrations(...$this->migrationFiles());

        $this->assertCount(1, $this->app->teachingGroups(), 'bestehende Fachgruppen bleiben erhalten');
        $this->assertCount(1, $this->app->teachingGroupClasses());
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        return array_map('basename', glob(dirname(__DIR__, 2) . '/setup/migrate/*_teaching_groups.php') ?: []);
    }
}
