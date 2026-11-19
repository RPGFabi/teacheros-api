<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Classes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Klassen ansehen und anlegen (Ticket #55) ueber HTTP gegen php-cgi + Test-DB:
 * GET /api/classes?school_year_id= und GET /api/classes/school-years (`classes.view`), POST /api/classes (`classes.manage`).
 */
final class ClassesEndpointsTest extends TestCase
{
    private const ADMIN = '55555555-0000-4555-8555-000000000001';
    private const VIEWER = '55555555-0000-4555-8555-000000000002';
    private const MANAGER = '55555555-0000-4555-8555-000000000003';
    private const NOBODY = '55555555-0000-4555-8555-000000000004';
    private const YEAR_VIEWER = '55555555-0000-4555-8555-000000000005';
    private const URI = '/api/classes';

    private HttpTestApp $app;
    private string $year;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->resetSchoolYears();
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        $this->app->createAccount(self::VIEWER, 'active');
        $this->app->grantPermission(self::VIEWER, 'classes.view');
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'classes.manage');
        $this->app->createAccount(self::NOBODY, 'active');
        $this->app->createAccount(self::YEAR_VIEWER, 'active');
        $this->app->grantPermission(self::YEAR_VIEWER, 'school_years.view');
        $this->year = $this->createYear('2090-09-01', '2091-07-31');
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

    private function list(?string $yearId = null, string $sub = self::ADMIN, string $uri = self::URI): array
    {
        $query = $yearId === null ? '' : '?school_year_id=' . rawurlencode($yearId);
        return $this->app->request('GET', $uri . $query, $this->headers($sub));
    }

    private function create(array $body, string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->app->request('POST', $uri, $this->headers($sub), $body);
    }

    private function createClass(int $grade, string $name, ?string $yearId = null): array
    {
        return $this->create(['school_year_id' => $yearId ?? $this->year, 'grade_level' => $grade, 'name' => $name]);
    }

    // --- Rechte ---

    public function testKatalogFuehrtBeideKlassenRechteInDerGruppeStammdaten(): void
    {
        $r = $this->app->request('GET', '/api/permissions', $this->headers(self::ADMIN));

        $byKey = array_column($r['json']['permissions'], null, 'key');
        $this->assertSame(['key' => 'classes.view', 'name' => 'Klassen ansehen', 'group' => 'Stammdaten'], $byKey['classes.view']);
        $this->assertSame(['key' => 'classes.manage', 'name' => 'Klassen verwalten', 'group' => 'Stammdaten'], $byKey['classes.manage']);
    }

    public function testKeineStandardrolleHatEinKlassenRechtVoreingestellt(): void
    {
        $roles = $this->app->request('GET', '/api/roles', $this->headers(self::ADMIN))['json']['roles'];

        foreach ($roles as $role) {
            if ($role['key'] === 'schuladmin') {
                continue;
            }
            $this->assertNotContains('classes.view', $role['permissions'], $role['key']);
            $this->assertNotContains('classes.manage', $role['permissions'], $role['key']);
        }
    }

    public function testSchuladminHatBeideRechteUeberDieWildcard(): void
    {
        $me = $this->app->request('GET', '/api/me', $this->headers(self::ADMIN))['json'];

        $this->assertContains('classes.view', $me['permissions']);
        $this->assertContains('classes.manage', $me['permissions']);
    }

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('GET', self::URI . '?school_year_id=' . $this->year)['status']);
        $this->assertSame(401, $this->app->request('GET', self::URI . '/school-years')['status']);
        $this->assertSame(401, $this->app->request('POST', self::URI, [], ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7a'])['status']);
    }

    public function testLesenBrauchtClassesViewSonst403Forbidden(): void
    {
        foreach ([self::NOBODY, self::MANAGER, self::YEAR_VIEWER] as $sub) {
            foreach ([$this->list($this->year, $sub), $this->list(null, $sub, self::URI . '/school-years')] as $r) {
                $this->assertSame(403, $r['status'], $sub);
                $this->assertSame('forbidden', $r['json']['code'], $sub);
            }
        }
        $this->assertSame(200, $this->list($this->year, self::VIEWER)['status']);
        $this->assertSame(200, $this->list(null, self::VIEWER, self::URI . '/school-years')['status']);
    }

    public function testAnlegenBrauchtClassesManageSonst403Forbidden(): void
    {
        foreach ([self::NOBODY, self::VIEWER, self::YEAR_VIEWER] as $sub) {
            $r = $this->create(['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7a'], $sub);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('forbidden', $r['json']['code'], $sub);
        }
        $this->assertSame([], $this->app->classes(), 'ohne Recht wird nichts angelegt');
        $this->assertSame(201, $this->create(['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7a'], self::MANAGER)['status']);
    }

    public function testClassesViewSetztSchoolYearsViewNichtVoraus(): void
    {
        $this->createClass(7, '7a');

        $r = $this->list($this->year, self::VIEWER);

        $this->assertSame(200, $r['status']);
        $this->assertCount(1, $r['json']['classes']);
        $this->assertSame(403, $this->list(null, self::VIEWER, '/api/school-years')['status'], 'die Schuljahr-Liste selbst bleibt geschuetzt');
    }

    public function testModulpruefungLaeuftVorDerRechtepruefung(): void
    {
        // Dieselben Routen, aber unter dem Modul `observe` (ohne Freigabe) eingehaengt: tests/Support/module-probe-routes.php.
        $this->app->resetModules();
        $probe = '/api/test/observe-classes';

        $admin = $this->list($this->year, self::ADMIN, $probe);
        $this->assertSame(403, $admin['status']);
        $this->assertSame('module_disabled', $admin['json']['code']);

        $nobody = $this->create(['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7a'], self::NOBODY, $probe);
        $this->assertSame(403, $nobody['status']);
        $this->assertSame('module_disabled', $nobody['json']['code'], 'der Grund ist das Modul, nicht das fehlende Recht');
        $this->assertSame('module_disabled', $this->list(null, self::NOBODY, $probe . '/school-years')['json']['code']);
        $this->assertSame([], $this->app->classes());

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

    public function testAnlegenLiefert201MitKlassenstufeBezeichnungUndSchuljahr(): void
    {
        $r = $this->createClass(7, '7a');

        $this->assertSame(201, $r['status'], $r['body']);
        $class = $r['json']['class'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $class['id']);
        $this->assertSame(7, $class['grade_level']);
        $this->assertSame('7a', $class['name']);
        $this->assertSame(['id' => $this->year, 'name' => '2090/91'], $class['school_year']);
        $this->assertSame(['id', 'grade_level', 'name', 'archived', 'school_year'], array_keys($class));
        $this->assertCount(1, $this->app->classes());
    }

    public function testAnlegenIstInLaufendemKommendemUndVergangenemSchuljahrMoeglich(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $running = $this->createYear($today->modify('-30 days')->format('Y-m-d'), ((int) $today->modify('-30 days')->format('Y') + 1) . '-07-31');
        $past = $this->createYear('2000-09-01', '2001-07-31');

        foreach ([$running, $past, $this->year] as $yearId) {
            $this->assertSame(201, $this->createClass(5, '5a', $yearId)['status'], $yearId);
        }
        $this->assertCount(3, $this->app->classes());
    }

    public function testBezeichnungWirdVorDemSpeichernGetrimmt(): void
    {
        $r = $this->createClass(7, '  7a  ');

        $this->assertSame('7a', $r['json']['class']['name']);
        $this->assertSame('7a', $this->app->classes()[0]['name']);
    }

    public function testGleicheBezeichnungInEinemAnderenSchuljahrIstErlaubt(): void
    {
        $other = $this->createYear('2091-08-01', '2092-07-31');

        $this->assertSame(201, $this->createClass(7, '7a')['status']);
        $this->assertSame(201, $this->createClass(8, '7a', $other)['status'], 'im Folgejahr ist 7a eine neue Klasse');
    }

    public function testAnlegenSchreibtAuditEintragNurMitKennungUndBezeichnung(): void
    {
        $id = $this->createClass(7, '7a')['json']['class']['id'];

        $entries = array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'class.created'));
        $this->assertCount(1, $entries);
        $this->assertSame('class', $entries[0]['object_type']);
        $this->assertSame($id, $entries[0]['object_id']);
        $this->assertSame('account', $entries[0]['actor_type']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['name' => '7a'], json_decode($entries[0]['context'], true));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function ungueltigeEingaben(): iterable
    {
        yield 'ohne Klassenstufe' => [['name' => '7a']];
        yield 'Klassenstufe 0' => [['grade_level' => 0, 'name' => '7a']];
        yield 'Klassenstufe 14' => [['grade_level' => 14, 'name' => '7a']];
        yield 'negative Klassenstufe' => [['grade_level' => -1, 'name' => '7a']];
        yield 'Klassenstufe als Text' => [['grade_level' => '7', 'name' => '7a']];
        yield 'Klassenstufe als Kommazahl' => [['grade_level' => 7.5, 'name' => '7a']];
        yield 'Klassenstufe null' => [['grade_level' => null, 'name' => '7a']];
        yield 'ohne Bezeichnung' => [['grade_level' => 7]];
        yield 'leere Bezeichnung' => [['grade_level' => 7, 'name' => '']];
        yield 'nur Leerzeichen' => [['grade_level' => 7, 'name' => '   ']];
        yield 'Bezeichnung 21 Zeichen' => [['grade_level' => 7, 'name' => str_repeat('a', 21)]];
        yield 'Bezeichnung 21 Umlaute' => [['grade_level' => 7, 'name' => str_repeat('ä', 21)]];
        yield 'Bezeichnung keine Zeichenkette' => [['grade_level' => 7, 'name' => 7]];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('ungueltigeEingaben')]
    public function testUngueltigeEingabeWird400AbgelehntOhneAuditEintrag(array $body): void
    {
        $r = $this->create(['school_year_id' => $this->year] + $body);

        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertNotSame('', $r['json']['error']);
        $this->assertSame([], $this->app->classes());
        $this->assertNotContains('class.created', array_column($this->app->auditLog(), 'action'));
    }

    public function testGrenzwerteSindErlaubt(): void
    {
        foreach ([[1, 'a'], [13, str_repeat('a', 20)], [7, str_repeat('ä', 20)], [10, ' 10b ']] as [$grade, $name]) {
            $this->assertSame(201, $this->createClass($grade, $name)['status'], "$grade $name");
        }
    }

    public function testFehlendeOderUngueltigeSchuljahrKennungWird400UnbekanntesSchuljahrWird404(): void
    {
        foreach ([[], ['school_year_id' => ''], ['school_year_id' => 5]] as $extra) {
            $r = $this->create($extra + ['grade_level' => 7, 'name' => '7a'], self::ADMIN);
            $this->assertSame(400, $r['status'], json_encode($extra));
            $this->assertSame('invalid_request', $r['json']['code']);
        }

        $r = $this->createClass(7, '7a', '00000000-0000-4000-8000-000000000000');
        $this->assertSame(404, $r['status']);
        $this->assertSame('school_year_not_found', $r['json']['code']);
        $this->assertSame([], $this->app->classes());
    }

    // --- Eindeutigkeit ---

    /** @return iterable<string,array{string}> */
    public static function gleicheBezeichnungen(): iterable
    {
        yield 'identisch' => ['7a'];
        yield 'andere Gross-/Kleinschreibung' => ['7A'];
        yield 'mit Leerzeichen drumherum' => ['  7a '];
    }

    #[DataProvider('gleicheBezeichnungen')]
    public function testBezeichnungIstImSchuljahrEindeutigOhneGrossKleinschreibung409(string $name): void
    {
        $this->createClass(7, '7a');

        $r = $this->createClass(8, $name);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('class_name_taken', $r['json']['code']);
        $this->assertCount(1, $this->app->classes());
        $this->assertCount(1, array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'class.created'));
    }

    public function testEindeutigkeitPrueftNichtGegenDieKlassenstufe(): void
    {
        $this->assertSame(201, $this->createClass(7, '7a')['status']);
        $this->assertSame(201, $this->createClass(7, '7b')['status'], 'gleiche Stufe, andere Bezeichnung');
        $this->assertSame(201, $this->createClass(9, '7c')['status'], 'Bezeichnung passt nicht zur Stufe: kein Fehler');
    }

    public function testUnterschiedlicheZeichenSindKeineDuplikate(): void
    {
        $this->assertSame(201, $this->createClass(7, '7a')['status']);

        $this->assertSame(201, $this->createClass(7, '7ä')['status'], 'Umlaut ist ein anderes Zeichen als der Grundbuchstabe');
    }

    public function testZweiGleichzeitigeAnfragenMitGleicherBezeichnungLegenNurEineKlasseAn(): void
    {
        $call = ['POST', self::URI, $this->headers(self::ADMIN), ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7a']];

        $responses = $this->app->requestConcurrently([$call, $call, $call]);

        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409, 409], $statuses);
        $this->assertCount(1, $this->app->classes());
    }

    // --- Lesen ---

    public function testListeIstLeerOhneKlassen(): void
    {
        $r = $this->list($this->year, self::VIEWER);

        $this->assertSame(200, $r['status']);
        $this->assertSame(['classes' => []], $r['json']);
    }

    public function testListeSortiertNachKlassenstufeDannNatuerlichNachBezeichnung(): void
    {
        foreach ([[10, '10a'], [7, '7b'], [7, '7a'], [5, '5c'], [7, '7ab'], [7, '7A2'], [7, '7a10'], [7, '7a9']] as [$grade, $name]) {
            $this->createClass($grade, $name);
        }
        $this->createClass(10, '10b');

        $classes = $this->list($this->year, self::VIEWER)['json']['classes'];

        $this->assertSame(['5c', '7a', '7A2', '7a9', '7a10', '7ab', '7b', '10a', '10b'], array_column($classes, 'name'));
        $this->assertSame([5, 7, 7, 7, 7, 7, 7, 10, 10], array_column($classes, 'grade_level'));
    }

    public function testBezeichnungenMitZahlenInnerhalbDerStufeSindNatuerlichSortiert(): void
    {
        foreach ([[7, '7b'], [7, '7a'], [10, '10a'], [8, '8a']] as [$grade, $name]) {
            $this->createClass($grade, $name);
        }

        $names = array_column($this->list($this->year, self::VIEWER)['json']['classes'], 'name');

        $this->assertSame(['7a', '7b', '8a', '10a'], $names, 'nach Stufe, nicht als Text: 10a nach 7b');
    }

    public function testListeZeigtNurDieKlassenDesGewaehltenSchuljahresMitDessenKennungUndBezeichnung(): void
    {
        $other = $this->createYear('2091-08-01', '2092-07-31');
        $this->createClass(7, '7a');
        $this->createClass(8, '8a', $other);

        $classes = $this->list($other, self::VIEWER)['json']['classes'];

        $this->assertCount(1, $classes);
        $this->assertSame('8a', $classes[0]['name']);
        $this->assertSame(['id' => $other, 'name' => '2091/92'], $classes[0]['school_year']);
    }

    public function testListeOhneSchuljahrKennungIst400UndUnbekanntesSchuljahrIst404(): void
    {
        $missing = $this->list(null, self::VIEWER);
        $this->assertSame(400, $missing['status']);
        $this->assertSame('invalid_request', $missing['json']['code']);

        $unknown = $this->list('00000000-0000-4000-8000-000000000000', self::VIEWER);
        $this->assertSame(404, $unknown['status']);
        $this->assertSame('school_year_not_found', $unknown['json']['code']);
    }

    // --- Schuljahr-Auswahl ---

    public function testSchuljahrAuswahlLiefertAlleSchuljahreMitStatusNachBeginnOhneSchoolYearsView(): void
    {
        $this->createYear('2000-09-01', '2001-07-31');

        $r = $this->list(null, self::VIEWER, self::URI . '/school-years');

        $this->assertSame(200, $r['status']);
        $years = $r['json']['school_years'];
        $this->assertSame(['2000/01', '2090/91'], array_column($years, 'name'));
        $this->assertSame(['past', 'running'], array_column($years, 'status'), 'derselbe Status wie in der Schuljahr-Liste');
        $this->assertSame(['id', 'name', 'starts_on', 'ends_on', 'status'], array_keys($years[0]));
    }

    // --- Migration ---

    public function testMigrationIstGefahrlosWiederholbar(): void
    {
        $this->createClass(7, '7a');

        $this->app->rerunMigrations('0014_classes.php');

        $this->assertCount(1, $this->app->classes(), 'bestehende Klassen bleiben erhalten');
    }

    public function testEinSchuljahrMitKlassenLaesstSichNichtLoeschen(): void
    {
        $this->createClass(7, '7a');

        $this->expectException(\PDOException::class);
        $this->app->pdo()->exec('DELETE FROM `school_years`');
    }
}
