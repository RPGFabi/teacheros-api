<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Classes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Klasse bearbeiten, archivieren, wiederherstellen und loeschen (Ticket #57, GLOSSARY.md: Archivierung) ueber HTTP
 * gegen php-cgi + Test-DB: POST /api/classes/:id/update|archive|restore|delete (`classes.manage`), dazu der Filter
 * `include_archived` der Liste und die Abfrage GET /api/classes/selectable (`classes.view`).
 */
final class ClassLifecycleTest extends TestCase
{
    private const ADMIN = '57575757-0000-4555-8555-000000000001';
    private const VIEWER = '57575757-0000-4555-8555-000000000002';
    private const MANAGER = '57575757-0000-4555-8555-000000000003';
    private const NOBODY = '57575757-0000-4555-8555-000000000004';
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

    /** Legt eine Klasse an und liefert ihre Kennung. */
    private function createClass(int $grade, string $name, ?string $yearId = null): string
    {
        $r = $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['school_year_id' => $yearId ?? $this->year, 'grade_level' => $grade, 'name' => $name]);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json']['class']['id'];
    }

    /** @param array<string,mixed>|null $body */
    private function act(string $id, string $action, ?array $body = [], string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->app->request('POST', $uri . '/' . $id . '/' . $action, $this->headers($sub), $body);
    }

    /** @return array<string,mixed> */
    private function row(string $id): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM `classes` WHERE `id` = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function auditFor(string $action): array
    {
        return array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === $action));
    }

    // --- Rechte und Modulpruefung ---

    /** @return iterable<string,array{string,array<string,mixed>}> */
    public static function manageActions(): iterable
    {
        yield 'bearbeiten' => ['update', ['grade_level' => 8, 'name' => '8a']];
        yield 'archivieren' => ['archive', []];
        yield 'wiederherstellen' => ['restore', []];
        yield 'loeschen' => ['delete', []];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('manageActions')]
    public function testAlleAktionenBrauchenClassesManageSonst403Forbidden(string $action, array $body): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');
        $before = [$this->row($id), count($this->app->auditLog())];

        foreach ([self::NOBODY, self::VIEWER] as $sub) {
            $r = $this->act($id, $action, $body, $sub);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('forbidden', $r['json']['code'], $sub);
        }
        $this->assertSame($before, [$this->row($id), count($this->app->auditLog())], 'ohne Recht aendert sich nichts');
        $this->assertNotSame(403, $this->act($id, $action, $body, self::MANAGER)['status'], 'mit classes.manage kommt die Anfrage durch');
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('manageActions')]
    public function testOhneTokenKommt401(string $action, array $body): void
    {
        $id = $this->createClass(7, '7a');

        $this->assertSame(401, $this->app->request('POST', self::URI . '/' . $id . '/' . $action, [], $body)['status']);
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('manageActions')]
    public function testModulpruefungLaeuftVorDerRechtepruefung(string $action, array $body): void
    {
        $id = $this->createClass(7, '7a');
        $uri = '/api/test/observe-classes';
        // Modul `observe` ist nicht freigegeben: Admin und Konto ohne Recht bekommen dieselbe Ablehnung vor jeder Rechtepruefung
        foreach ([self::ADMIN, self::NOBODY] as $sub) {
            $r = $this->act($id, $action, $body, $sub, $uri);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('module_disabled', $r['json']['code'], $sub);
        }
        $this->assertSame('7a', $this->row($id)['name']);
    }

    public function testWaehlbareKlassenBrauchenClassesViewSonst403Forbidden(): void
    {
        foreach ([self::NOBODY, self::MANAGER] as $sub) {
            $r = $this->app->request('GET', self::URI . '/selectable', $this->headers($sub));
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('forbidden', $r['json']['code'], $sub);
        }
        $this->assertSame(401, $this->app->request('GET', self::URI . '/selectable')['status']);
        $r = $this->app->request('GET', '/api/test/observe-classes/selectable', $this->headers(self::ADMIN));
        $this->assertSame('module_disabled', $r['json']['code']);
    }

    // --- Bearbeiten ---

    public function testBearbeitenAendertKlassenstufeUndBezeichnung(): void
    {
        $id = $this->createClass(7, '7a');

        $r = $this->act($id, 'update', ['grade_level' => 8, 'name' => '  8b ']);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(
            ['id' => $id, 'grade_level' => 8, 'name' => '8b', 'archived' => false, 'school_year' => ['id' => $this->year, 'name' => '2090/91']],
            $r['json']['class'],
        );
        $this->assertSame('8b', $this->row($id)['name']);
        $this->assertSame(8, (int) $this->row($id)['grade_level']);
    }

    public function testBearbeitenPflegtDenEindeutigkeitsschluesselMit(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'update', ['grade_level' => 7, 'name' => '7B']);

        $this->assertSame('7b', $this->row($id)['name_key']);
        // die alte Bezeichnung ist wieder frei, die neue belegt
        $this->createClass(7, '7a');
        $r = $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7b']);
        $this->assertSame(409, $r['status']);
        $this->assertSame('class_name_taken', $r['json']['code']);
    }

    public function testBearbeitenMitGleicherBezeichnungAnderersSchreibungOderNurStufeIstErlaubt(): void
    {
        $id = $this->createClass(7, '7a');

        $this->assertSame(200, $this->act($id, 'update', ['grade_level' => 7, 'name' => '7A'])['status'], 'eigene Bezeichnung in anderer Schreibung');
        $this->assertSame(200, $this->act($id, 'update', ['grade_level' => 9, 'name' => '7A'])['status'], 'nur die Klassenstufe');
        $this->assertSame('7A', $this->row($id)['name']);
    }

    public function testBearbeitenMitBezeichnungEinerAnderenKlasseDesSchuljahresIst409(): void
    {
        $this->createClass(7, '7a');
        $id = $this->createClass(7, '7b');

        $r = $this->act($id, 'update', ['grade_level' => 7, 'name' => '7A']);

        $this->assertSame(409, $r['status']);
        $this->assertSame('class_name_taken', $r['json']['code']);
        $this->assertSame('7b', $this->row($id)['name'], 'unveraendert');
        $this->assertSame([], $this->auditFor('class.updated'));
    }

    public function testBearbeitenMitBezeichnungAusEinemAnderenSchuljahrIstErlaubt(): void
    {
        $other = $this->createYear('2091-08-01', '2092-07-31');
        $this->createClass(7, '7a', $other);
        $id = $this->createClass(7, '7b');

        $this->assertSame(200, $this->act($id, 'update', ['grade_level' => 7, 'name' => '7a'])['status']);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function ungueltigeEingaben(): iterable
    {
        yield 'ohne Klassenstufe' => [['name' => '7a']];
        yield 'Klassenstufe 0' => [['grade_level' => 0, 'name' => '7a']];
        yield 'Klassenstufe 14' => [['grade_level' => 14, 'name' => '7a']];
        yield 'Klassenstufe als Text' => [['grade_level' => '7', 'name' => '7a']];
        yield 'ohne Bezeichnung' => [['grade_level' => 7]];
        yield 'leere Bezeichnung' => [['grade_level' => 7, 'name' => '   ']];
        yield 'Bezeichnung 21 Zeichen' => [['grade_level' => 7, 'name' => str_repeat('a', 21)]];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('ungueltigeEingaben')]
    public function testBearbeitenWendetDieRegelnDesAnlegensAn(array $body): void
    {
        $id = $this->createClass(7, '7a');

        $r = $this->act($id, 'update', $body);

        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertSame('7a', $this->row($id)['name']);
        $this->assertSame([], $this->auditFor('class.updated'));
    }

    public function testDasSchuljahrLaesstSichNichtAendern(): void
    {
        $other = $this->createYear('2091-08-01', '2092-07-31');
        $id = $this->createClass(7, '7a');

        $r = $this->act($id, 'update', ['school_year_id' => $other, 'grade_level' => 7, 'name' => '7a']);

        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertSame($this->year, $this->row($id)['school_year_id']);
        // dieselbe Kennung mitzuschicken ist harmlos
        $this->assertSame(200, $this->act($id, 'update', ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7a'])['status']);
    }

    public function testBearbeitenEinerUnbekanntenKlasseIst404(): void
    {
        $r = $this->act('00000000-0000-4000-8000-000000000000', 'update', ['grade_level' => 7, 'name' => '7a']);

        $this->assertSame(404, $r['status']);
        $this->assertSame('class_not_found', $r['json']['code']);
    }

    public function testBearbeitenSchreibtAuditEintragMitAlterUndNeuerBezeichnung(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'update', ['grade_level' => 8, 'name' => '8a']);

        $entries = $this->auditFor('class.updated');
        $this->assertCount(1, $entries);
        $this->assertSame('class', $entries[0]['object_type']);
        $this->assertSame($id, $entries[0]['object_id']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(
            ['old_name' => '7a', 'name' => '8a'],
            json_decode($entries[0]['context'], true),
        );
    }

    // --- Archivieren und Wiederherstellen ---

    /** @return list<array<string,mixed>> */
    private function listClasses(bool $withArchived = false, ?string $yearId = null, string $sub = self::ADMIN): array
    {
        $uri = self::URI . '?school_year_id=' . rawurlencode($yearId ?? $this->year) . ($withArchived ? '&include_archived=1' : '');
        $r = $this->app->request('GET', $uri, $this->headers($sub));
        $this->assertSame(200, $r['status'], $r['body']);
        return $r['json']['classes'];
    }

    public function testArchivierenLiefertDieKlasseAlsArchiviert(): void
    {
        $id = $this->createClass(7, '7a');

        $r = $this->act($id, 'archive');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(
            ['id' => $id, 'grade_level' => 7, 'name' => '7a', 'archived' => true, 'school_year' => ['id' => $this->year, 'name' => '2090/91']],
            $r['json']['class'],
        );
        $this->assertNotNull($this->row($id)['archived_at']);
    }

    public function testArchivierteKlassenSindStandardmaessigAusgeblendetUndMitFilterSichtbar(): void
    {
        $archived = $this->createClass(7, '7a');
        $active = $this->createClass(7, '7b');
        $this->act($archived, 'archive');

        $this->assertSame([$active], array_column($this->listClasses(), 'id'));
        $withArchived = $this->listClasses(true);
        $this->assertSame([$archived, $active], array_column($withArchived, 'id'));
        $this->assertSame([true, false], array_column($withArchived, 'archived'));
        $this->assertSame([false], array_column($this->listClasses(), 'archived'));
    }

    public function testDerFilterIstNurMitEinsOderTrueAn(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');

        foreach (['1', 'true'] as $value) {
            $r = $this->app->request('GET', self::URI . '?school_year_id=' . $this->year . '&include_archived=' . $value, $this->headers(self::ADMIN));
            $this->assertCount(1, $r['json']['classes'], $value);
        }
        foreach (['0', 'false', ''] as $value) {
            $r = $this->app->request('GET', self::URI . '?school_year_id=' . $this->year . '&include_archived=' . $value, $this->headers(self::ADMIN));
            $this->assertCount(0, $r['json']['classes'], $value);
        }
    }

    public function testDieBezeichnungEinerArchiviertenKlasseBleibtImSchuljahrBelegt(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');

        $r = $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7A']);
        $this->assertSame(409, $r['status']);
        $this->assertSame('class_name_taken', $r['json']['code']);

        $other = $this->createYear('2091-08-01', '2092-07-31');
        $this->assertSame(201, $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['school_year_id' => $other, 'grade_level' => 7, 'name' => '7a'])['status'], 'im anderen Schuljahr frei');
    }

    public function testBearbeitenEinerArchiviertenKlasseWirdAbgelehnt(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');

        $r = $this->act($id, 'update', ['grade_level' => 8, 'name' => '8a']);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('class_archived', $r['json']['code']);
        $this->assertSame('7a', $this->row($id)['name']);
        $this->assertSame([], $this->auditFor('class.updated'));
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('ungueltigeEingaben')]
    public function testBeiEinerArchiviertenKlasseGehtDieArchivPruefungVorDerEingabepruefung(array $body): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');

        $r = $this->act($id, 'update', $body);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('class_archived', $r['json']['code']);
    }

    public function testBeiEinerArchiviertenKlasseGehtDieArchivPruefungAuchVorDemAbweichendenSchuljahr(): void
    {
        $other = $this->createYear('2091-08-01', '2092-07-31');
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');

        $r = $this->act($id, 'update', ['school_year_id' => $other, 'grade_level' => 7, 'name' => '7a']);

        $this->assertSame('class_archived', $r['json']['code']);
    }

    public function testArchivierenEinerSchonArchiviertenKlasseIst409(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');

        $r = $this->act($id, 'archive');

        $this->assertSame(409, $r['status']);
        $this->assertSame('class_already_archived', $r['json']['code']);
        $this->assertCount(1, $this->auditFor('class.archived'));
    }

    public function testWiederherstellenMachtDieKlasseWiederAenderbarUndSichtbar(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');

        $r = $this->act($id, 'restore');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertFalse($r['json']['class']['archived']);
        $this->assertNull($this->row($id)['archived_at']);
        $this->assertSame([$id], array_column($this->listClasses(), 'id'));
        $this->assertSame(200, $this->act($id, 'update', ['grade_level' => 8, 'name' => '8a'])['status']);
    }

    public function testWiederherstellenBrauchtKeineEindeutigkeitspruefungDaDieBezeichnungBelegtBlieb(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');
        // die Bezeichnung konnte in der Zwischenzeit nicht vergeben werden, also gibt es beim Zurueckholen keinen Konflikt
        $this->assertSame(409, $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7a'])['status']);

        $this->assertSame(200, $this->act($id, 'restore')['status']);
        $this->assertSame(1, count($this->app->classes()));
    }

    public function testWiederherstellenEinerNichtArchiviertenKlasseIst409(): void
    {
        $id = $this->createClass(7, '7a');

        $r = $this->act($id, 'restore');

        $this->assertSame(409, $r['status']);
        $this->assertSame('class_not_archived', $r['json']['code']);
        $this->assertSame([], $this->auditFor('class.restored'));
    }

    public function testArchivierenUndWiederherstellenEinerUnbekanntenKlasseIst404(): void
    {
        foreach (['archive', 'restore', 'delete'] as $action) {
            $r = $this->act('00000000-0000-4000-8000-000000000000', $action);
            $this->assertSame(404, $r['status'], $action);
            $this->assertSame('class_not_found', $r['json']['code'], $action);
        }
    }

    public function testArchivierenUndWiederherstellenSchreibenAuditEintraege(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');
        $this->act($id, 'restore');

        foreach (['class.archived', 'class.restored'] as $action) {
            $entries = $this->auditFor($action);
            $this->assertCount(1, $entries, $action);
            $this->assertSame('class', $entries[0]['object_type']);
            $this->assertSame($id, $entries[0]['object_id']);
            $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
            $this->assertSame(['name' => '7a'], json_decode($entries[0]['context'], true));
        }
    }

    // --- Nur waehlbare Klassen ---

    public function testWaehlbareKlassenEinesSchuljahresOhneArchivierte(): void
    {
        $archived = $this->createClass(7, '7a');
        $active = $this->createClass(7, '7b');
        $this->act($archived, 'archive');

        $r = $this->app->request('GET', self::URI . '/selectable?school_year_id=' . $this->year, $this->headers(self::VIEWER));

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame([$active], array_column($r['json']['classes'], 'id'));
        $this->assertSame(['id', 'grade_level', 'name', 'archived', 'school_year'], array_keys($r['json']['classes'][0]));
    }

    public function testWaehlbareKlassenOhneSchuljahrUmfassenAlleSchuljahreNachBeginn(): void
    {
        $other = $this->createYear('2000-09-01', '2001-07-31');
        $newer = $this->createClass(7, '7a');
        $older = $this->createClass(9, '9a', $other);
        $gone = $this->createClass(5, '5a', $other);
        $this->act($gone, 'archive');

        $r = $this->app->request('GET', self::URI . '/selectable', $this->headers(self::VIEWER));

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame([$older, $newer], array_column($r['json']['classes'], 'id'));
        $this->assertSame(['2000/01', '2090/91'], array_map(static fn (array $c): string => $c['school_year']['name'], $r['json']['classes']));
    }

    public function testWaehlbareKlassenMitUnbekanntemOderLeeremSchuljahrWerden404Und400(): void
    {
        $unknown = $this->app->request('GET', self::URI . '/selectable?school_year_id=nope', $this->headers(self::VIEWER));
        $this->assertSame(404, $unknown['status']);
        $this->assertSame('school_year_not_found', $unknown['json']['code']);

        $empty = $this->app->request('GET', self::URI . '/selectable?school_year_id=', $this->headers(self::VIEWER));
        $this->assertSame(400, $empty['status']);
    }

    // --- Loeschen und Verknuepfungspruefung ---

    public function testLoeschenEinerUnverknuepftenKlasseEntferntSieEndgueltig(): void
    {
        $id = $this->createClass(7, '7a');
        $keep = $this->createClass(7, '7b');

        $r = $this->act($id, 'delete');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['deleted' => true, 'id' => $id], $r['json']);
        $this->assertSame([$keep], array_column($this->app->classes(), 'id'));
        $this->assertSame([$keep], array_column($this->listClasses(true), 'id'));
    }

    public function testNachDemLoeschenIstDieBezeichnungWiederFrei(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'delete');

        $this->createClass(8, '7a');
        $this->assertCount(1, $this->app->classes());
    }

    public function testLoeschenSchreibtAuditEintrag(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'delete');

        $entries = $this->auditFor('class.deleted');
        $this->assertCount(1, $entries);
        $this->assertSame('class', $entries[0]['object_type']);
        $this->assertSame($id, $entries[0]['object_id']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['name' => '7a'], json_decode($entries[0]['context'], true));
    }

    public function testEineVerknuepfteKlasseWirdNichtGeloeschtUndAufArchivierenVerwiesen(): void
    {
        $id = $this->createClass(7, '7a');
        $this->app->linkClass($id);

        $r = $this->act($id, 'delete');

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('class_linked', $r['json']['code']);
        $this->assertStringContainsString('archivieren', $r['json']['error']);
        $this->assertStringContainsString('Testverknüpfung', $r['json']['error'], 'nennt, woran die Klasse haengt');
        $this->assertSame([$id], array_column($this->app->classes(), 'id'));
        $this->assertSame([], $this->auditFor('class.deleted'));
    }

    public function testNurDieVerknuepfteKlasseIstGesperrt(): void
    {
        $linked = $this->createClass(7, '7a');
        $free = $this->createClass(7, '7b');
        $this->app->linkClass($linked);

        $this->assertSame(409, $this->act($linked, 'delete')['status']);
        $this->assertSame(200, $this->act($free, 'delete')['status']);
    }

    public function testEineVerknuepfteKlasseLaesstSichArchivierenUndDannNochImmerNichtLoeschen(): void
    {
        $id = $this->createClass(7, '7a');
        $this->app->linkClass($id);

        $this->assertSame(200, $this->act($id, 'archive')['status']);
        $r = $this->act($id, 'delete');
        $this->assertSame(409, $r['status']);
        $this->assertSame('class_linked', $r['json']['code']);
    }

    public function testEineArchivierteUnverknuepfteKlasseLaesstSichLoeschen(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');

        $this->assertSame(200, $this->act($id, 'delete')['status']);
        $this->assertSame([], $this->app->classes());
    }

    public function testMigrationDerArchivierungIstGefahrlosWiederholbar(): void
    {
        $id = $this->createClass(7, '7a');
        $this->act($id, 'archive');

        $this->app->rerunMigrations('0016_classes_archived.php');

        $this->assertNotNull($this->row($id)['archived_at'], 'Archivierung bleibt erhalten');
    }
}
