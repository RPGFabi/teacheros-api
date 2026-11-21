<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Subjects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Fach bearbeiten, archivieren, wiederherstellen und loeschen (Ticket #63, GLOSSARY.md: Archivierung) ueber HTTP
 * gegen php-cgi + Test-DB: POST /api/subjects/:id/update|archive|restore|delete (`subjects.manage`), dazu der Filter
 * `include_archived` der Liste und die Abfrage GET /api/subjects/selectable (`subjects.view`).
 */
final class SubjectLifecycleTest extends TestCase
{
    private const ADMIN = '63636363-0000-4563-8563-000000000001';
    private const VIEWER = '63636363-0000-4563-8563-000000000002';
    private const MANAGER = '63636363-0000-4563-8563-000000000003';
    private const NOBODY = '63636363-0000-4563-8563-000000000004';
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

    /** Legt ein Fach an und liefert seine Kennung. */
    private function createSubject(string $code, string $name = 'Fach'): string
    {
        $r = $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['code' => $code, 'name' => $name]);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json']['subject']['id'];
    }

    /** @param array<string,mixed>|null $body */
    private function act(string $id, string $action, ?array $body = [], string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->app->request('POST', $uri . '/' . $id . '/' . $action, $this->headers($sub), $body);
    }

    /** @return array<string,mixed> */
    private function row(string $id): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM `subjects` WHERE `id` = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function auditFor(string $action): array
    {
        return array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === $action));
    }

    /** @return list<array<string,mixed>> */
    private function listSubjects(bool $withArchived = false, string $sub = self::ADMIN, string $uri = self::URI): array
    {
        $r = $this->app->request('GET', $uri . ($withArchived ? '?include_archived=1' : ''), $this->headers($sub));
        $this->assertSame(200, $r['status'], $r['body']);
        return $r['json']['subjects'];
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
    public function testAlleAktionenBrauchenSubjectsManageSonst403Forbidden(string $action, array $body): void
    {
        $id = $this->createSubject('Ma', 'Mathematik');
        $this->act($id, 'archive');
        $before = [$this->row($id), count($this->app->auditLog())];

        foreach ([self::NOBODY, self::VIEWER] as $sub) {
            $r = $this->act($id, $action, $body, $sub);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('forbidden', $r['json']['code'], $sub);
        }
        $this->assertSame($before, [$this->row($id), count($this->app->auditLog())], 'ohne Recht aendert sich nichts');
        $this->assertNotSame(403, $this->act($id, $action, $body, self::MANAGER)['status'], 'mit subjects.manage kommt die Anfrage durch');
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('manageActions')]
    public function testOhneTokenKommt401(string $action, array $body): void
    {
        $id = $this->createSubject('Ma');

        $this->assertSame(401, $this->app->request('POST', self::URI . '/' . $id . '/' . $action, [], $body)['status']);
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('manageActions')]
    public function testModulpruefungLaeuftVorDerRechtepruefung(string $action, array $body): void
    {
        $id = $this->createSubject('Ma', 'Mathematik');
        $uri = '/api/test/observe-subjects';
        // Modul `observe` ist nicht freigegeben: Admin und Konto ohne Recht bekommen dieselbe Ablehnung vor jeder Rechtepruefung
        foreach ([self::ADMIN, self::NOBODY] as $sub) {
            $r = $this->act($id, $action, $body, $sub, $uri);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('module_disabled', $r['json']['code'], $sub);
        }
        $this->assertSame('Mathematik', $this->row($id)['name']);
        $this->assertNull($this->row($id)['archived_at']);
    }

    // --- Bearbeiten ---

    public function testBearbeitenAendertDieBezeichnungUndLaesstDasKuerzelUnveraendert(): void
    {
        $id = $this->createSubject('Ma', 'Mathe');

        $r = $this->act($id, 'update', ['name' => '  Mathematik ']);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['id' => $id, 'code' => 'Ma', 'name' => 'Mathematik', 'archived' => false], $r['json']['subject']);
        $this->assertSame('Mathematik', $this->row($id)['name']);
        $this->assertSame('Ma', $this->row($id)['code']);
        $this->assertSame('ma', $this->row($id)['code_key']);
    }

    public function testBearbeitenMitDemselbenKuerzelMitzuschickenIstHarmlos(): void
    {
        $id = $this->createSubject('Ma', 'Mathe');

        $this->assertSame(200, $this->act($id, 'update', ['code' => 'Ma', 'name' => 'Mathematik'])['status']);
        $this->assertSame(200, $this->act($id, 'update', ['code' => ' Ma ', 'name' => 'Mathematik 2'])['status'], 'wie beim Anlegen getrimmt');
    }

    /** @return iterable<string,array{mixed}> */
    public static function abweichendeKuerzel(): iterable
    {
        yield 'anderes Kuerzel' => ['Bi'];
        yield 'andere Schreibung' => ['MA'];
        yield 'leer' => [''];
        yield 'kein Text' => [5];
    }

    #[DataProvider('abweichendeKuerzel')]
    public function testEinAbweichendesKuerzelWirdMit400AbgelehntUndNichtsGeaendert(mixed $code): void
    {
        $id = $this->createSubject('Ma', 'Mathe');

        $r = $this->act($id, 'update', ['code' => $code, 'name' => 'Mathematik']);

        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertStringContainsString('Kürzel', $r['json']['error']);
        $this->assertSame(['Ma', 'Mathe'], [$this->row($id)['code'], $this->row($id)['name']]);
        $this->assertSame([], $this->auditFor('subject.updated'));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function ungueltigeBezeichnungen(): iterable
    {
        yield 'ohne Bezeichnung' => [[]];
        yield 'leere Bezeichnung' => [['name' => '   ']];
        yield 'Bezeichnung kein Text' => [['name' => 12]];
        yield 'Bezeichnung 61 Zeichen' => [['name' => str_repeat('a', 61)]];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('ungueltigeBezeichnungen')]
    public function testBearbeitenWendetDieRegelnDesAnlegensAn(array $body): void
    {
        $id = $this->createSubject('Ma', 'Mathe');

        $r = $this->act($id, 'update', $body);

        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertSame('Mathe', $this->row($id)['name']);
        $this->assertSame([], $this->auditFor('subject.updated'));
    }

    public function testBearbeitenErlaubtDieGrenzenUndEineBezeichnungEinesAnderenFachs(): void
    {
        $this->createSubject('Bi', 'Mathematik');
        $id = $this->createSubject('Ma', 'Mathe');

        $this->assertSame(200, $this->act($id, 'update', ['name' => str_repeat('a', 60)])['status']);
        $this->assertSame(200, $this->act($id, 'update', ['name' => 'x'])['status']);
        $this->assertSame(200, $this->act($id, 'update', ['name' => 'Mathematik'])['status'], 'Bezeichnungen muessen nicht eindeutig sein');
    }

    public function testBearbeitenEinesUnbekanntenFachsIst404(): void
    {
        $r = $this->act('00000000-0000-4000-8000-000000000000', 'update', ['name' => 'Neu']);

        $this->assertSame(404, $r['status']);
        $this->assertSame('subject_not_found', $r['json']['code']);
    }

    public function testBearbeitenSchreibtAuditEintragMitAlterUndNeuerBezeichnung(): void
    {
        $id = $this->createSubject('Ma', 'Mathe');
        $this->act($id, 'update', ['name' => 'Mathematik']);

        $entries = $this->auditFor('subject.updated');
        $this->assertCount(1, $entries);
        $this->assertSame('subject', $entries[0]['object_type']);
        $this->assertSame($id, $entries[0]['object_id']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['code' => 'Ma', 'old_name' => 'Mathe', 'name' => 'Mathematik'], json_decode($entries[0]['context'], true));
    }

    // --- Archivieren und Wiederherstellen ---

    public function testArchivierenLiefertDasFachAlsArchiviert(): void
    {
        $id = $this->createSubject('Ma', 'Mathematik');

        $r = $this->act($id, 'archive');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['id' => $id, 'code' => 'Ma', 'name' => 'Mathematik', 'archived' => true], $r['json']['subject']);
        $this->assertNotNull($this->row($id)['archived_at']);
    }

    public function testArchivierteFaecherSindStandardmaessigAusgeblendetUndMitFilterSichtbarUndMarkiert(): void
    {
        $archived = $this->createSubject('Bi', 'Biologie');
        $active = $this->createSubject('Ma', 'Mathematik');
        $this->act($archived, 'archive');

        $this->assertSame([$active], array_column($this->listSubjects(), 'id'));
        $all = $this->listSubjects(true);
        $this->assertSame([$archived, $active], array_column($all, 'id'));
        $this->assertSame([true, false], array_column($all, 'archived'));
        $this->assertSame(['id', 'code', 'name', 'archived'], array_keys($all[0]));
    }

    public function testDerFilterIstNurMitEinsOderTrueAn(): void
    {
        $id = $this->createSubject('Bi');
        $this->act($id, 'archive');

        foreach (['1' => 1, 'true' => 1, '0' => 0, 'false' => 0, '' => 0, 'yes' => 0] as $value => $count) {
            $r = $this->app->request('GET', self::URI . '?include_archived=' . $value, $this->headers(self::VIEWER));
            $this->assertCount($count, $r['json']['subjects'], 'include_archived=' . $value);
        }
    }

    public function testDasKuerzelEinesArchiviertenFachsBleibtBelegt(): void
    {
        $id = $this->createSubject('Ma');
        $this->act($id, 'archive');

        $r = $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['code' => 'MA', 'name' => 'Noch ein Fach']);

        $this->assertSame(409, $r['status']);
        $this->assertSame('subject_code_taken', $r['json']['code']);
    }

    public function testArchivierteFaecherSindNichtWaehlbar(): void
    {
        $archived = $this->createSubject('Bi');
        $active = $this->createSubject('Ma');
        $this->act($archived, 'archive');

        $selectable = $this->listSubjects(false, self::VIEWER, self::URI . '/selectable');
        $this->assertSame([$active], array_column($selectable, 'id'));
        // der Filter der Liste ist an der Abfrage der waehlbaren Faecher wirkungslos
        $r = $this->app->request('GET', self::URI . '/selectable?include_archived=1', $this->headers(self::VIEWER));
        $this->assertSame([$active], array_column($r['json']['subjects'], 'id'));
    }

    public function testBearbeitenEinesArchiviertenFachsWirdAbgelehnt(): void
    {
        $id = $this->createSubject('Ma', 'Mathe');
        $this->act($id, 'archive');

        $r = $this->act($id, 'update', ['name' => 'Mathematik']);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('subject_archived', $r['json']['code']);
        $this->assertSame('Mathe', $this->row($id)['name']);
        $this->assertSame([], $this->auditFor('subject.updated'));
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('ungueltigeBezeichnungen')]
    public function testBeiEinemArchiviertenFachGehtDiePruefungDesArchivsVorDerEingabepruefung(array $body): void
    {
        $id = $this->createSubject('Ma', 'Mathe');
        $this->act($id, 'archive');

        $this->assertSame('subject_archived', $this->act($id, 'update', $body)['json']['code']);
        $this->assertSame('subject_archived', $this->act($id, 'update', ['code' => 'Bi', 'name' => 'x'])['json']['code']);
    }

    public function testArchivierenEinesSchonArchiviertenFachsIst409(): void
    {
        $id = $this->createSubject('Ma');
        $this->act($id, 'archive');
        $archivedAt = $this->row($id)['archived_at'];

        $r = $this->act($id, 'archive');

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('subject_already_archived', $r['json']['code']);
        $this->assertSame($archivedAt, $this->row($id)['archived_at']);
        $this->assertCount(1, $this->auditFor('subject.archived'));
    }

    public function testWiederherstellenMachtDasFachWiederAenderbarUndWaehlbar(): void
    {
        $id = $this->createSubject('Ma', 'Mathe');
        $this->act($id, 'archive');

        $r = $this->act($id, 'restore');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['id' => $id, 'code' => 'Ma', 'name' => 'Mathe', 'archived' => false], $r['json']['subject']);
        $this->assertNull($this->row($id)['archived_at']);
        $this->assertSame([$id], array_column($this->listSubjects(), 'id'));
        $this->assertSame([$id], array_column($this->listSubjects(false, self::VIEWER, self::URI . '/selectable'), 'id'));
        $this->assertSame(200, $this->act($id, 'update', ['name' => 'Mathematik'])['status']);
    }

    public function testWiederherstellenBrauchtKeineEindeutigkeitspruefungDaDasKuerzelBelegtBlieb(): void
    {
        $id = $this->createSubject('Ma');
        $this->act($id, 'archive');
        $this->createSubject('Bi');

        $this->assertSame(200, $this->act($id, 'restore')['status']);
        $this->assertCount(2, $this->app->subjects());
    }

    public function testWiederherstellenEinesNichtArchiviertenFachsIst409(): void
    {
        $id = $this->createSubject('Ma');

        $r = $this->act($id, 'restore');

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('subject_not_archived', $r['json']['code']);
        $this->assertSame([], $this->auditFor('subject.restored'));
    }

    public function testArchivierenWiederherstellenUndLoeschenEinesUnbekanntenFachsIst404(): void
    {
        foreach (['archive', 'restore', 'delete'] as $action) {
            $r = $this->act('00000000-0000-4000-8000-000000000000', $action);
            $this->assertSame(404, $r['status'], $action);
            $this->assertSame('subject_not_found', $r['json']['code'], $action);
        }
    }

    public function testArchivierenUndWiederherstellenSchreibenAuditEintraege(): void
    {
        $id = $this->createSubject('Ma', 'Mathematik');
        $this->act($id, 'archive');
        $this->act($id, 'restore');

        foreach (['subject.archived', 'subject.restored'] as $action) {
            $entries = $this->auditFor($action);
            $this->assertCount(1, $entries, $action);
            $this->assertSame('subject', $entries[0]['object_type']);
            $this->assertSame($id, $entries[0]['object_id']);
            $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
            $this->assertSame(['code' => 'Ma', 'name' => 'Mathematik'], json_decode($entries[0]['context'], true));
        }
    }

    // --- Loeschen und Verknuepfungspruefung ---

    public function testLoeschenEinesUnverknuepftenFachsEntferntEsEndgueltig(): void
    {
        $id = $this->createSubject('Ma');
        $keep = $this->createSubject('Bi');

        $r = $this->act($id, 'delete');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['deleted' => true, 'id' => $id], $r['json']);
        $this->assertSame([$keep], array_column($this->app->subjects(), 'id'));
        $this->assertSame([$keep], array_column($this->listSubjects(true), 'id'));
    }

    public function testNachDemLoeschenIstDasKuerzelWiederFrei(): void
    {
        $id = $this->createSubject('Ma');
        $this->act($id, 'delete');

        $this->createSubject('ma');
        $this->assertCount(1, $this->app->subjects());
    }

    public function testLoeschenSchreibtAuditEintrag(): void
    {
        $id = $this->createSubject('Ma', 'Mathematik');
        $this->act($id, 'delete');

        $entries = $this->auditFor('subject.deleted');
        $this->assertCount(1, $entries);
        $this->assertSame('subject', $entries[0]['object_type']);
        $this->assertSame($id, $entries[0]['object_id']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['code' => 'Ma', 'name' => 'Mathematik'], json_decode($entries[0]['context'], true));
    }

    public function testEinVerknuepftesFachWirdNichtGeloeschtUndAufArchivierenVerwiesen(): void
    {
        $id = $this->createSubject('Ma');
        $this->app->linkSubject($id);

        $r = $this->act($id, 'delete');

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('subject_linked', $r['json']['code']);
        $this->assertStringContainsString('archivieren', $r['json']['error']);
        $this->assertStringContainsString('Testverknüpfung', $r['json']['error'], 'nennt, woran das Fach haengt');
        $this->assertSame([$id], array_column($this->app->subjects(), 'id'));
        $this->assertSame([], $this->auditFor('subject.deleted'));
    }

    public function testNurDasVerknuepfteFachIstGesperrt(): void
    {
        $linked = $this->createSubject('Ma');
        $free = $this->createSubject('Bi');
        $this->app->linkSubject($linked);

        $this->assertSame(409, $this->act($linked, 'delete')['status']);
        $this->assertSame(200, $this->act($free, 'delete')['status']);
    }

    public function testEinVerknuepftesFachLaesstSichArchivierenUndDannNochImmerNichtLoeschen(): void
    {
        $id = $this->createSubject('Ma');
        $this->app->linkSubject($id);

        $this->assertSame(200, $this->act($id, 'archive')['status']);
        $r = $this->act($id, 'delete');
        $this->assertSame(409, $r['status']);
        $this->assertSame('subject_linked', $r['json']['code']);
    }

    public function testEinArchiviertesUnverknuepftesFachLaesstSichLoeschen(): void
    {
        $id = $this->createSubject('Ma');
        $this->act($id, 'archive');

        $this->assertSame(200, $this->act($id, 'delete')['status']);
        $this->assertSame([], $this->app->subjects());
    }

    public function testMigrationDerArchivierungIstGefahrlosWiederholbar(): void
    {
        $id = $this->createSubject('Ma');
        $this->act($id, 'archive');

        $this->app->rerunMigrations('0021_subjects_archived.php');

        $this->assertNotNull($this->row($id)['archived_at'], 'Archivierung bleibt erhalten');
    }
}
