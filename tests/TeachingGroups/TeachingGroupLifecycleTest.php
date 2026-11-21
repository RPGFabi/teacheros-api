<?php

declare(strict_types=1);

namespace TeacherOS\Tests\TeachingGroups;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Fachgruppe bearbeiten, archivieren, wiederherstellen und loeschen (Ticket #65, GLOSSARY.md: Fachgruppe, Archivierung) ueber
 * HTTP gegen php-cgi + Test-DB: POST /api/teaching-groups/:id/update|archive|restore|delete (`teaching_groups.manage`), dazu
 * der Filter `include_archived` der Liste und die Abfrage GET /api/teaching-groups/selectable (`teaching_groups.view`).
 */
final class TeachingGroupLifecycleTest extends TestCase
{
    private const ADMIN = '65656565-0000-4565-8565-000000000001';
    private const VIEWER = '65656565-0000-4565-8565-000000000002';
    private const MANAGER = '65656565-0000-4565-8565-000000000003';
    private const NOBODY = '65656565-0000-4565-8565-000000000004';
    private const URI = '/api/teaching-groups';

    private HttpTestApp $app;
    private string $year;
    private string $subject;
    private string $class7a;
    private string $class7b;
    private string $class8a;
    private string $group;

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
        $this->class8a = $this->createClass(8, '8a');
        $this->group = $this->createGroup('Ma 7', [$this->class7a]);
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

    /** @param list<string> $classIds */
    private function createGroup(string $name, array $classIds, ?string $subjectId = null, ?string $yearId = null): string
    {
        $r = $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['school_year_id' => $yearId ?? $this->year, 'subject_id' => $subjectId ?? $this->subject, 'name' => $name, 'class_ids' => $classIds]);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json']['teaching_group']['id'];
    }

    /** @param array<string,mixed>|null $body */
    private function act(string $id, string $action, ?array $body = [], string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->app->request('POST', $uri . '/' . $id . '/' . $action, $this->headers($sub), $body);
    }

    /** @param list<string> $classIds */
    private function update(string $id, string $name, array $classIds, array $more = []): array
    {
        return $this->act($id, 'update', ['name' => $name, 'class_ids' => $classIds] + $more);
    }

    /** @return array<string,mixed> */
    private function row(string $id): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM `teaching_groups` WHERE `id` = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return list<string> Klassen-Kennungen der Fachgruppe, sortiert */
    private function classIdsOf(string $id): array
    {
        $ids = array_column(array_filter($this->app->teachingGroupClasses(), static fn (array $r): bool => $r['teaching_group_id'] === $id), 'class_id');
        sort($ids);
        return $ids;
    }

    /** @return list<array<string,mixed>> */
    private function auditFor(string $action): array
    {
        return array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === $action));
    }

    /** @return list<array<string,mixed>> */
    private function listGroups(bool $withArchived = false, string $sub = self::ADMIN): array
    {
        $r = $this->app->request('GET', self::URI . '?school_year_id=' . $this->year . ($withArchived ? '&include_archived=1' : ''), $this->headers($sub));
        $this->assertSame(200, $r['status'], $r['body']);
        return $r['json']['teaching_groups'];
    }

    /** @return list<array<string,mixed>> */
    private function selectable(?string $yearId = null, string $sub = self::VIEWER): array
    {
        $r = $this->app->request('GET', self::URI . '/selectable' . ($yearId === null ? '' : '?school_year_id=' . $yearId), $this->headers($sub));
        $this->assertSame(200, $r['status'], $r['body']);
        return $r['json']['teaching_groups'];
    }

    // --- Rechte und Modulpruefung ---

    /** @return iterable<string,array{string,array<string,mixed>}> */
    public static function manageActions(): iterable
    {
        yield 'bearbeiten' => ['update', ['name' => 'Neu']];
        yield 'archivieren' => ['archive', []];
        yield 'wiederherstellen' => ['restore', []];
        yield 'loeschen' => ['delete', []];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('manageActions')]
    public function testAlleAktionenBrauchenTeachingGroupsManageSonst403Forbidden(string $action, array $body): void
    {
        $this->act($this->group, 'archive');
        $before = [$this->row($this->group), $this->classIdsOf($this->group), count($this->app->auditLog())];

        foreach ([self::NOBODY, self::VIEWER] as $sub) {
            $r = $this->act($this->group, $action, $body, $sub);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('forbidden', $r['json']['code'], $sub);
        }
        $this->assertSame($before, [$this->row($this->group), $this->classIdsOf($this->group), count($this->app->auditLog())], 'ohne Recht aendert sich nichts');
        $this->assertNotSame(403, $this->act($this->group, $action, $body, self::MANAGER)['status'], 'mit teaching_groups.manage kommt die Anfrage durch');
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('manageActions')]
    public function testOhneTokenKommt401(string $action, array $body): void
    {
        $this->assertSame(401, $this->app->request('POST', self::URI . '/' . $this->group . '/' . $action, [], $body)['status']);
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('manageActions')]
    public function testModulpruefungLaeuftVorDerRechtepruefung(string $action, array $body): void
    {
        $uri = '/api/test/observe-teaching-groups';
        // Modul `observe` ist nicht freigegeben: Admin und Konto ohne Recht bekommen dieselbe Ablehnung vor jeder Rechtepruefung
        foreach ([self::ADMIN, self::NOBODY] as $sub) {
            $r = $this->act($this->group, $action, $body, $sub, $uri);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('module_disabled', $r['json']['code'], $sub);
        }
        $this->assertSame('Ma 7', $this->row($this->group)['name']);
        $this->assertNull($this->row($this->group)['archived_at']);
        $this->assertCount(1, $this->app->teachingGroups());
    }

    // --- Bearbeiten ---

    public function testBearbeitenAendertBezeichnungUndKlassenUndLiefertDieFachgruppe(): void
    {
        $r = $this->update($this->group, 'Ma 7/8', [$this->class8a, $this->class7b]);

        $this->assertSame(200, $r['status'], $r['body']);
        $group = $r['json']['teaching_group'];
        $this->assertSame($this->group, $group['id']);
        $this->assertSame('Ma 7/8', $group['name']);
        $this->assertSame(['7b', '8a'], array_column($group['classes'], 'name'), 'Klassen nach Stufe und Bezeichnung');
        $this->assertSame(['id' => $this->year, 'name' => '2090/91'], $group['school_year']);
        $this->assertSame('Ma', $group['subject']['code']);
        $this->assertFalse($group['archived']);
        $expected = [$this->class7b, $this->class8a];
        sort($expected);
        $this->assertSame($expected, $this->classIdsOf($this->group));
        $this->assertSame('Ma 7/8', $this->row($this->group)['name']);
        $this->assertSame('ma 7/8', $this->row($this->group)['name_key']);
    }

    public function testKlassenHinzufuegenUndBezeichnungBleibt(): void
    {
        $r = $this->update($this->group, 'Ma 7', [$this->class7a, $this->class7b]);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['7a', '7b'], array_column($r['json']['teaching_group']['classes'], 'name'));
    }

    public function testBearbeitenMitSchuljahrUndFachDerFachgruppeMitzuschickenIstHarmlos(): void
    {
        $r = $this->update($this->group, 'Ma 7', [$this->class7a], ['school_year_id' => $this->year, 'subject_id' => $this->subject]);

        $this->assertSame(200, $r['status'], $r['body']);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function changedFixedValues(): iterable
    {
        yield 'anderes Schuljahr' => [['school_year_id' => '00000000-0000-4000-8000-000000000000']];
        yield 'anderes Fach' => [['subject_id' => '00000000-0000-4000-8000-000000000000']];
        yield 'Schuljahr als Zahl' => [['school_year_id' => 1]];
        yield 'Fach leer' => [['subject_id' => '']];
        yield 'Fach als Liste' => [['subject_id' => []]];
    }

    /** @param array<string,mixed> $more */
    #[DataProvider('changedFixedValues')]
    public function testEinAbweichendesSchuljahrOderFachWirdMit400AbgelehntUndNichtsGeaendert(array $more): void
    {
        $r = $this->update($this->group, 'Neu', [$this->class7b], $more);

        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertSame('Ma 7', $this->row($this->group)['name']);
        $this->assertSame([$this->class7a], $this->classIdsOf($this->group));
        $this->assertSame([], $this->auditFor('teaching_group.updated'));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'ohne Bezeichnung' => [['class_ids' => ['KLASSE']]];
        yield 'leere Bezeichnung' => [['name' => '   ', 'class_ids' => ['KLASSE']]];
        yield 'Bezeichnung als Zahl' => [['name' => 7, 'class_ids' => ['KLASSE']]];
        yield 'zu lange Bezeichnung' => [['name' => str_repeat('x', 41), 'class_ids' => ['KLASSE']]];
        yield 'ohne Klassen' => [['name' => 'Neu']];
        yield 'leere Klassenliste' => [['name' => 'Neu', 'class_ids' => []]];
        yield 'Klassen als Text' => [['name' => 'Neu', 'class_ids' => 'KLASSE']];
        yield 'Klassen mit Zahl' => [['name' => 'Neu', 'class_ids' => ['KLASSE', 5]]];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('invalidBodies')]
    public function testBearbeitenWendetDieRegelnDesAnlegensAn(array $body): void
    {
        $body = json_decode(str_replace('KLASSE', $this->class7b, json_encode($body)), true);

        $r = $this->act($this->group, 'update', $body);

        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertSame('Ma 7', $this->row($this->group)['name']);
        $this->assertSame([$this->class7a], $this->classIdsOf($this->group));
    }

    public function testBearbeitenErlaubtDieGrenzenDerBezeichnung(): void
    {
        $this->assertSame(200, $this->update($this->group, 'x', [$this->class7a])['status']);
        $this->assertSame(200, $this->update($this->group, str_repeat('y', 40), [$this->class7a])['status']);
    }

    public function testDoppelteKlassenZaehlenEinmal(): void
    {
        $r = $this->update($this->group, 'Ma 7', [$this->class7b, $this->class7b]);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame([$this->class7b], $this->classIdsOf($this->group));
    }

    public function testNurDieGrossKleinschreibungDerBezeichnungLaesstSichAendern(): void
    {
        $r = $this->update($this->group, 'MA 7', [$this->class7a]);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame('MA 7', $r['json']['teaching_group']['name']);
    }

    public function testEineBezeichnungEinerAnderenFachgruppeDesSchuljahresIstBelegt(): void
    {
        $this->createGroup('Ma 8', [$this->class8a]);

        $r = $this->update($this->group, 'ma 8', [$this->class7a]);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('teaching_group_name_taken', $r['json']['code']);
        $this->assertSame('Ma 7', $this->row($this->group)['name']);
        $this->assertSame([], $this->auditFor('teaching_group.updated'));
    }

    public function testBearbeitenEinerUnbekanntenFachgruppeIst404(): void
    {
        $r = $this->update('00000000-0000-4000-8000-000000000000', 'Neu', [$this->class7a]);

        $this->assertSame(404, $r['status']);
        $this->assertSame('teaching_group_not_found', $r['json']['code']);
    }

    public function testBearbeitenNimmtNurWaehlbareKlassenDesSchuljahresNeuAuf(): void
    {
        $otherYear = $this->createYear('2092-09-01', '2093-07-31');
        $foreign = $this->createClass(7, '7a', $otherYear);
        $archived = $this->createClass(9, '9a');
        $this->act($archived, 'archive', [], self::ADMIN, '/api/classes');
        $unknown = '00000000-0000-4000-8000-000000000000';

        foreach ([$foreign, $archived, $unknown] as $classId) {
            $r = $this->update($this->group, 'Ma 7', [$this->class7a, $classId]);
            $this->assertSame(409, $r['status'], $r['body']);
            $this->assertSame('class_not_selectable', $r['json']['code']);
        }
        $this->assertSame([$this->class7a], $this->classIdsOf($this->group));
    }

    public function testEineNachDemVerknuepfenArchivierteKlasseBleibtVerknuepftUndDarfInDerListeBleiben(): void
    {
        $this->act($this->class7a, 'archive', [], self::ADMIN, '/api/classes');

        $r = $this->update($this->group, 'Ma 7 neu', [$this->class7a, $this->class7b]);

        $this->assertSame(200, $r['status'], $r['body']);
        $classes = $r['json']['teaching_group']['classes'];
        $this->assertSame(['7a', '7b'], array_column($classes, 'name'));
        $this->assertSame([true, false], array_column($classes, 'archived'));
    }

    public function testBearbeitenOhneDieArchivierteKlasseEntferntSieWieJedeAndere(): void
    {
        $this->update($this->group, 'Ma 7', [$this->class7a, $this->class7b]);
        $this->act($this->class7a, 'archive', [], self::ADMIN, '/api/classes');

        $r = $this->update($this->group, 'Ma 7', [$this->class7b]);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame([$this->class7b], $this->classIdsOf($this->group));
    }

    public function testEineArchivierteKlasseLaesstSichNichtNachtraeglichHinzufuegen(): void
    {
        $this->act($this->class7b, 'archive', [], self::ADMIN, '/api/classes');

        $r = $this->update($this->group, 'Ma 7', [$this->class7a, $this->class7b]);

        $this->assertSame('class_not_selectable', $r['json']['code']);
    }

    public function testMindestensEineKlasseBleibt(): void
    {
        $r = $this->update($this->group, 'Ma 7', []);

        $this->assertSame(400, $r['status']);
        $this->assertSame([$this->class7a], $this->classIdsOf($this->group));
    }

    public function testEinEinwandGegenDasEntfernenEinerKlasseLehntAbUndAendertNichts(): void
    {
        $this->update($this->group, 'Ma 7', [$this->class7a, $this->class7b]);
        $this->app->blockClassRemoval($this->group, $this->class7a);
        $before = count($this->auditFor('teaching_group.updated'));

        $r = $this->update($this->group, 'Ma 7 neu', [$this->class7b, $this->class8a]);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('test_class_removal_blocked', $r['json']['code']);
        $this->assertSame('Test: Entfernen der Klasse gesperrt.', $r['json']['error']);
        $this->assertSame('Ma 7', $this->row($this->group)['name'], 'auch die Bezeichnung bleibt');
        $expected = [$this->class7a, $this->class7b];
        sort($expected);
        $this->assertSame($expected, $this->classIdsOf($this->group), 'auch das Hinzufuegen unterbleibt');
        $this->assertCount($before, $this->auditFor('teaching_group.updated'));
    }

    public function testDerEinwandBetrifftNurDieseKlasseInDieserFachgruppeUndNurDasEntfernen(): void
    {
        $other = $this->createGroup('Ma 8', [$this->class7a, $this->class8a]);
        $this->update($this->group, 'Ma 7', [$this->class7a, $this->class7b]);
        $this->app->blockClassRemoval($this->group, $this->class7a);

        // Die Klasse bleibt: kein Einwand, auch wenn andere Klassen wechseln
        $this->assertSame(200, $this->update($this->group, 'Ma 7', [$this->class7a, $this->class8a])['status']);
        // Eine andere Klasse entfernen ist frei
        $this->assertSame(200, $this->update($this->group, 'Ma 7', [$this->class7a])['status']);
        // Dieselbe Klasse in einer anderen Fachgruppe entfernen ist frei
        $this->assertSame(200, $this->update($other, 'Ma 8', [$this->class8a])['status']);
        // Die Klasse nur umzubenennen oder die Bezeichnung zu aendern ist frei
        $this->assertSame(200, $this->update($this->group, 'Ma 7 b', [$this->class7a])['status']);
    }

    public function testBearbeitenSchreibtAuditEintragMitAlterUndNeuerBezeichnungUndAnzahlDerKlassen(): void
    {
        $this->update($this->group, 'Ma 7/8', [$this->class7b, $this->class8a]);

        $entries = $this->auditFor('teaching_group.updated');
        $this->assertCount(1, $entries);
        $this->assertSame('teaching_group', $entries[0]['object_type']);
        $this->assertSame($this->group, $entries[0]['object_id']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['old_name' => 'Ma 7', 'name' => 'Ma 7/8', 'classes_added' => 2, 'classes_removed' => 1], json_decode($entries[0]['context'], true));
    }

    // --- Archivieren und Wiederherstellen ---

    public function testArchivierenLiefertDieFachgruppeAlsArchiviertMitIhrenKlassen(): void
    {
        $r = $this->act($this->group, 'archive');

        $this->assertSame(200, $r['status'], $r['body']);
        $group = $r['json']['teaching_group'];
        $this->assertSame(['id', 'name', 'archived', 'school_year', 'subject', 'classes'], array_keys($group));
        $this->assertTrue($group['archived']);
        $this->assertSame(['7a'], array_column($group['classes'], 'name'));
        $this->assertNotNull($this->row($this->group)['archived_at']);
        $this->assertSame([$this->class7a], $this->classIdsOf($this->group), 'Klassen bleiben verknuepft');
    }

    public function testArchivierteFachgruppenSindStandardmaessigAusgeblendetUndMitFilterSichtbarUndMarkiert(): void
    {
        $active = $this->createGroup('En 7', [$this->class7b], $this->createSubject('En', 'Englisch'));
        $this->act($this->group, 'archive');

        $this->assertSame([$active], array_column($this->listGroups(), 'id'));
        $all = $this->listGroups(true);
        $this->assertSame([$active, $this->group], array_column($all, 'id'), 'nach Fachkuerzel: En vor Ma');
        $this->assertSame([false, true], array_column($all, 'archived'));
    }

    public function testDerFilterIstNurMitEinsOderTrueAn(): void
    {
        $this->act($this->group, 'archive');

        foreach (['1' => 1, 'true' => 1, '0' => 0, 'false' => 0, '' => 0, 'yes' => 0] as $value => $count) {
            $r = $this->app->request('GET', self::URI . '?school_year_id=' . $this->year . '&include_archived=' . $value, $this->headers(self::VIEWER));
            $this->assertCount($count, $r['json']['teaching_groups'], 'include_archived=' . $value);
        }
    }

    public function testEineArchivierteFachgruppeBleibtEinzelnLesbar(): void
    {
        $this->act($this->group, 'archive');

        $r = $this->app->request('GET', self::URI . '/' . $this->group, $this->headers(self::VIEWER));

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['json']['teaching_group']['archived']);
    }

    public function testDieBezeichnungEinerArchiviertenFachgruppeBleibtBelegt(): void
    {
        $this->act($this->group, 'archive');

        $r = $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['school_year_id' => $this->year, 'subject_id' => $this->subject, 'name' => 'MA 7', 'class_ids' => [$this->class7b]]);

        $this->assertSame(409, $r['status']);
        $this->assertSame('teaching_group_name_taken', $r['json']['code']);
    }

    public function testArchivierteFachgruppenSindNichtWaehlbar(): void
    {
        $active = $this->createGroup('En 7', [$this->class7b], $this->createSubject('En', 'Englisch'));
        $this->act($this->group, 'archive');

        $this->assertSame([$active], array_column($this->selectable($this->year), 'id'));
        $r = $this->app->request('GET', self::URI . '/selectable?school_year_id=' . $this->year . '&include_archived=1', $this->headers(self::VIEWER));
        $this->assertSame([$active], array_column($r['json']['teaching_groups'], 'id'), 'der Filter der Liste wirkt hier nicht');
    }

    public function testWaehlbareFachgruppenOhneSchuljahrGibtEsFuerAlleSchuljahreNachBeginn(): void
    {
        $later = $this->createYear('2092-09-01', '2093-07-31');
        $laterClass = $this->createClass(7, '7a', $later);
        $laterGroup = $this->createGroup('Ma 7', [$laterClass], null, $later);
        $earlier = $this->createGroup('En 7', [$this->class7b], $this->createSubject('En', 'Englisch'));

        $this->assertSame([$earlier, $this->group, $laterGroup], array_column($this->selectable(), 'id'));
        $this->assertSame([$laterGroup], array_column($this->selectable($later), 'id'));
    }

    public function testWaehlbareFachgruppenBrauchenTeachingGroupsViewUndEinSchuljahr404(): void
    {
        foreach ([self::NOBODY, self::MANAGER] as $sub) {
            $r = $this->app->request('GET', self::URI . '/selectable', $this->headers($sub));
            $this->assertSame(403, $r['status'], $sub);
        }
        $this->assertSame(401, $this->app->request('GET', self::URI . '/selectable')['status']);
        $r = $this->app->request('GET', self::URI . '/selectable?school_year_id=00000000-0000-4000-8000-000000000000', $this->headers(self::VIEWER));
        $this->assertSame(404, $r['status']);
        $this->assertSame('school_year_not_found', $r['json']['code']);
        $this->assertSame(400, $this->app->request('GET', self::URI . '/selectable?school_year_id=', $this->headers(self::VIEWER))['status']);
    }

    public function testBearbeitenEinerArchiviertenFachgruppeWirdAbgelehnt(): void
    {
        $this->act($this->group, 'archive');

        $r = $this->update($this->group, 'Neu', [$this->class7b]);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('teaching_group_archived', $r['json']['code']);
        $this->assertSame('Ma 7', $this->row($this->group)['name']);
        $this->assertSame([$this->class7a], $this->classIdsOf($this->group));
        $this->assertSame([], $this->auditFor('teaching_group.updated'));
    }

    public function testBeiEinerArchiviertenFachgruppeGehtDiePruefungDesArchivsVorDerEingabepruefung(): void
    {
        $this->act($this->group, 'archive');

        $r = $this->act($this->group, 'update', ['name' => '']);

        $this->assertSame(409, $r['status']);
        $this->assertSame('teaching_group_archived', $r['json']['code']);
    }

    public function testArchivierenEinerSchonArchiviertenFachgruppeIst409(): void
    {
        $this->act($this->group, 'archive');
        $audit = count($this->auditFor('teaching_group.archived'));

        $r = $this->act($this->group, 'archive');

        $this->assertSame(409, $r['status']);
        $this->assertSame('teaching_group_already_archived', $r['json']['code']);
        $this->assertCount($audit, $this->auditFor('teaching_group.archived'));
    }

    public function testWiederherstellenMachtDieFachgruppeWiederAenderbarUndWaehlbar(): void
    {
        $this->act($this->group, 'archive');

        $r = $this->act($this->group, 'restore');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertFalse($r['json']['teaching_group']['archived']);
        $this->assertNull($this->row($this->group)['archived_at']);
        $this->assertSame([$this->group], array_column($this->listGroups(), 'id'));
        $this->assertSame([$this->group], array_column($this->selectable($this->year), 'id'));
        $this->assertSame(200, $this->update($this->group, 'Neu', [$this->class7a])['status']);
    }

    public function testWiederherstellenEinerNichtArchiviertenFachgruppeIst409(): void
    {
        $r = $this->act($this->group, 'restore');

        $this->assertSame(409, $r['status']);
        $this->assertSame('teaching_group_not_archived', $r['json']['code']);
        $this->assertSame([], $this->auditFor('teaching_group.restored'));
    }

    public function testArchivierenWiederherstellenUndLoeschenEinerUnbekanntenFachgruppeIst404(): void
    {
        foreach (['archive', 'restore', 'delete'] as $action) {
            $r = $this->act('00000000-0000-4000-8000-000000000000', $action);
            $this->assertSame(404, $r['status'], $action);
            $this->assertSame('teaching_group_not_found', $r['json']['code'], $action);
        }
    }

    public function testArchivierenUndWiederherstellenSchreibenAuditEintraege(): void
    {
        $this->act($this->group, 'archive');
        $this->act($this->group, 'restore');

        foreach (['teaching_group.archived', 'teaching_group.restored'] as $action) {
            $entries = $this->auditFor($action);
            $this->assertCount(1, $entries, $action);
            $this->assertSame('teaching_group', $entries[0]['object_type']);
            $this->assertSame($this->group, $entries[0]['object_id']);
            $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
            $this->assertSame(['name' => 'Ma 7'], json_decode($entries[0]['context'], true));
        }
    }

    public function testEineFachgruppeMitArchiviertemFachBleibtArchivierbarUndWiederherstellbar(): void
    {
        $this->act($this->subject, 'archive', [], self::ADMIN, '/api/subjects');

        $this->assertSame(200, $this->act($this->group, 'archive')['status']);
        $this->assertSame(200, $this->act($this->group, 'restore')['status']);
    }

    // --- Loeschen ---

    public function testLoeschenEntferntDieFachgruppeSamtKlassenverknuepfungenUndLaesstDieKlassenStehen(): void
    {
        $this->update($this->group, 'Ma 7', [$this->class7a, $this->class7b]);

        $r = $this->act($this->group, 'delete');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['deleted' => true, 'id' => $this->group], $r['json']);
        $this->assertSame([], $this->app->teachingGroups());
        $this->assertSame([], $this->app->teachingGroupClasses());
        $this->assertCount(3, $this->app->classes());
        $this->assertCount(1, $this->app->subjects());
    }

    public function testNachDemLoeschenIstDieBezeichnungWiederFrei(): void
    {
        $this->act($this->group, 'delete');

        $this->assertSame(201, $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['school_year_id' => $this->year, 'subject_id' => $this->subject, 'name' => 'Ma 7', 'class_ids' => [$this->class7a]])['status']);
    }

    public function testLoeschenSchreibtAuditEintrag(): void
    {
        $this->act($this->group, 'delete');

        $entries = $this->auditFor('teaching_group.deleted');
        $this->assertCount(1, $entries);
        $this->assertSame('teaching_group', $entries[0]['object_type']);
        $this->assertSame($this->group, $entries[0]['object_id']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['name' => 'Ma 7'], json_decode($entries[0]['context'], true));
    }

    public function testEineVerknuepfteFachgruppeWirdNichtGeloeschtUndAufArchivierenVerwiesen(): void
    {
        $this->app->linkTeachingGroup($this->group);

        $r = $this->act($this->group, 'delete');

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('teaching_group_linked', $r['json']['code']);
        $this->assertStringContainsString('archivieren', $r['json']['error']);
        $this->assertStringContainsString('Testverknüpfung', $r['json']['error'], 'nennt, woran die Fachgruppe haengt');
        $this->assertCount(1, $this->app->teachingGroups());
        $this->assertSame([$this->class7a], $this->classIdsOf($this->group));
        $this->assertSame([], $this->auditFor('teaching_group.deleted'));
    }

    public function testNurDieVerknuepfteFachgruppeIstGesperrt(): void
    {
        $free = $this->createGroup('Ma 8', [$this->class8a]);
        $this->app->linkTeachingGroup($this->group);

        $this->assertSame(409, $this->act($this->group, 'delete')['status']);
        $this->assertSame(200, $this->act($free, 'delete')['status']);
    }

    public function testEineVerknuepfteFachgruppeLaesstSichArchivierenUndDannNochImmerNichtLoeschen(): void
    {
        $this->app->linkTeachingGroup($this->group);

        $this->assertSame(200, $this->act($this->group, 'archive')['status']);
        $r = $this->act($this->group, 'delete');
        $this->assertSame(409, $r['status']);
        $this->assertSame('teaching_group_linked', $r['json']['code']);
    }

    public function testEineArchivierteUnverknuepfteFachgruppeLaesstSichLoeschen(): void
    {
        $this->act($this->group, 'archive');

        $this->assertSame(200, $this->act($this->group, 'delete')['status']);
        $this->assertSame([], $this->app->teachingGroups());
    }

    public function testNachDemLoeschenIstDieKlasseWiederLoeschbar(): void
    {
        $this->assertSame('class_linked', $this->act($this->class7a, 'delete', [], self::ADMIN, '/api/classes')['json']['code']);

        $this->act($this->group, 'delete');

        $this->assertSame(200, $this->act($this->class7a, 'delete', [], self::ADMIN, '/api/classes')['status']);
    }

    public function testMigrationDerArchivierungIstGefahrlosWiederholbar(): void
    {
        $this->act($this->group, 'archive');

        $this->app->rerunMigrations('0023_teaching_groups_archived.php');

        $this->assertNotNull($this->row($this->group)['archived_at'], 'Archivierung bleibt erhalten');
    }
}
