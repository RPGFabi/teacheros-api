<?php

declare(strict_types=1);

namespace TeacherOS\Tests\SchoolYears;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Schuljahre bearbeiten und loeschen (Ticket #56) ueber HTTP gegen php-cgi + Test-DB:
 * POST /api/school-years/:id/update und /delete (`school_years.manage`).
 */
final class SchoolYearsUpdateDeleteTest extends TestCase
{
    private const ADMIN = '56000000-0000-4000-8000-000000000001';
    private const VIEWER = '56000000-0000-4000-8000-000000000002';
    private const MANAGER = '56000000-0000-4000-8000-000000000003';
    private const NOBODY = '56000000-0000-4000-8000-000000000004';
    private const URI = '/api/school-years';
    private const UNKNOWN_ID = '99999999-9999-4999-8999-999999999999';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->resetSchoolYears();
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        $this->app->createAccount(self::VIEWER, 'active');
        $this->app->grantPermission(self::VIEWER, 'school_years.view');
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'school_years.manage');
        $this->app->createAccount(self::NOBODY, 'active');
    }

    private function headers(string $sub): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)];
    }

    private function createYear(string $start, string $end): string
    {
        $r = $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['starts_on' => $start, 'ends_on' => $end]);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json']['school_year']['id'];
    }

    private function update(string $id, array $body, string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->app->request('POST', "$uri/$id/update", $this->headers($sub), $body);
    }

    private function delete(string $id, string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->app->request('POST', "$uri/$id/delete", $this->headers($sub));
    }

    private function createClass(string $yearId, string $name = '7a'): void
    {
        $r = $this->app->request('POST', '/api/classes', $this->headers(self::ADMIN), ['school_year_id' => $yearId, 'grade_level' => 7, 'name' => $name]);
        $this->assertSame(201, $r['status'], $r['body']);
    }

    /** @return list<array<string,mixed>> */
    private function audit(string $action): array
    {
        return array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === $action));
    }

    // --- Bearbeiten ---

    public function testBearbeitenVerschiebtDenZeitraumUndDieBezeichnungFolgt(): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29');

        $r = $this->update($id, ['starts_on' => '2091-09-01', 'ends_on' => '2092-07-31']);

        $this->assertSame(200, $r['status'], $r['body']);
        $year = $r['json']['school_year'];
        $this->assertSame($id, $year['id'], 'die Kennung bleibt');
        $this->assertSame('2091/92', $year['name']);
        $this->assertSame('2091-09-01', $year['starts_on']);
        $this->assertSame('2092-07-31', $year['ends_on']);
        $this->assertSame('upcoming', $year['status']);
        $rows = $this->app->schoolYears();
        $this->assertCount(1, $rows);
        $this->assertSame('2091-09-01', $rows[0]['starts_on']);
    }

    public function testBearbeitenSchreibtAuditEintragMitAlterUndNeuerBezeichnung(): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29');

        $this->update($id, ['starts_on' => '2091-09-01', 'ends_on' => '2092-07-31']);

        $entries = $this->audit('school_year.updated');
        $this->assertCount(1, $entries);
        $this->assertSame('school_year', $entries[0]['object_type']);
        $this->assertSame($id, $entries[0]['object_id']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['old_name' => '2090/91', 'name' => '2091/92'], json_decode($entries[0]['context'], true));
    }

    public function testBearbeitenEinesUnbekanntenSchuljahresWird404(): void
    {
        $r = $this->update(self::UNKNOWN_ID, ['starts_on' => '2091-09-01', 'ends_on' => '2092-07-31']);

        $this->assertSame(404, $r['status']);
        $this->assertSame('school_year_not_found', $r['json']['code']);
        $this->assertSame([], $this->audit('school_year.updated'));
    }

    public function testBearbeitenOhneAenderungIstErlaubtUndBehaeltDieBezeichnung(): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29');

        $r = $this->update($id, ['starts_on' => '2090-09-14', 'ends_on' => '2091-07-29']);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame('2090/91', $r['json']['school_year']['name']);
    }

    public function testBearbeitenUeberschneidetSichNichtMitSichSelbstAberMitAnderen(): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29');
        $this->createYear('2091-09-01', '2092-07-31');

        $own = $this->update($id, ['starts_on' => '2090-08-01', 'ends_on' => '2091-08-31']);
        $other = $this->update($id, ['starts_on' => '2090-10-01', 'ends_on' => '2091-09-01']);

        $this->assertSame(200, $own['status'], 'ausdehnen im eigenen Zeitraum ist keine Ueberschneidung mit sich selbst');
        $this->assertSame(409, $other['status'], $other['body']);
        $this->assertSame('school_year_overlap', $other['json']['code']);
        $this->assertSame('2091-08-31', $this->app->schoolYears()[0]['ends_on'], 'der abgelehnte Versuch aendert nichts');
        $this->assertCount(1, $this->audit('school_year.updated'));
    }

    /** @return iterable<string,array{int,string,array<string,mixed>}> */
    public static function ungueltigeAenderungen(): iterable
    {
        yield 'Datum im falschen Format' => [400, 'invalid_request', ['starts_on' => '01.09.2091', 'ends_on' => '2092-07-31']];
        yield 'Ende fehlt' => [400, 'invalid_request', ['starts_on' => '2091-09-01']];
        yield 'leerer Body' => [400, 'invalid_request', []];
        yield 'Ende nicht im Folgejahr' => [422, 'invalid_period', ['starts_on' => '2091-09-01', 'ends_on' => '2091-12-31']];
        yield 'Ende zwei Jahre spaeter' => [422, 'invalid_period', ['starts_on' => '2091-09-01', 'ends_on' => '2093-01-01']];
    }

    #[DataProvider('ungueltigeAenderungen')]
    public function testUngueltigeEingabeWirdAbgelehntUndAendertNichts(int $status, string $code, array $body): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29');

        $r = $this->update($id, $body);

        $this->assertSame($status, $r['status'], $r['body']);
        $this->assertSame($code, $r['json']['code']);
        $this->assertSame('2090-09-14', $this->app->schoolYears()[0]['starts_on']);
        $this->assertSame([], $this->audit('school_year.updated'));
    }

    // --- Loeschen ---

    public function testLoeschenEinesSchuljahresOhneKlassenEntferntEsUndSchreibtAudit(): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29');
        $other = $this->createYear('2091-09-01', '2092-07-31');

        $r = $this->delete($id);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['deleted' => true, 'id' => $id], $r['json']);
        $this->assertSame([$other], array_column($this->app->schoolYears(), 'id'));
        $entries = $this->audit('school_year.deleted');
        $this->assertCount(1, $entries);
        $this->assertSame('school_year', $entries[0]['object_type']);
        $this->assertSame($id, $entries[0]['object_id']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['name' => '2090/91'], json_decode($entries[0]['context'], true));
    }

    public function testLoeschenEinesUnbekanntenSchuljahresWird404(): void
    {
        $r = $this->delete(self::UNKNOWN_ID);

        $this->assertSame(404, $r['status']);
        $this->assertSame('school_year_not_found', $r['json']['code']);
        $this->assertSame([], $this->audit('school_year.deleted'));
    }

    public function testGeloeschtesSchuljahrIstNichtMehrBearbeitbarUndZeitraumWiederFrei(): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29');
        $this->delete($id);

        $this->assertSame(404, $this->update($id, ['starts_on' => '2090-09-14', 'ends_on' => '2091-07-29'])['status']);
        $this->assertSame(201, $this->app->request('POST', self::URI, $this->headers(self::ADMIN), ['starts_on' => '2090-09-14', 'ends_on' => '2091-07-29'])['status']);
    }

    public function testLoeschenWirdAbgelehntSobaldDasSchuljahrKlassenHat(): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29');
        $this->createClass($id);

        $r = $this->delete($id);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('school_year_has_classes', $r['json']['code']);
        $this->assertNotSame('', $r['json']['error']);
        $this->assertCount(1, $this->app->schoolYears());
        $this->assertCount(1, $this->app->classes());
        $this->assertSame([], $this->audit('school_year.deleted'));
    }

    public function testNurDasSchuljahrMitKlassenIstGesperrt(): void
    {
        $withClasses = $this->createYear('2090-09-14', '2091-07-29');
        $empty = $this->createYear('2091-09-01', '2092-07-31');
        $this->createClass($withClasses);

        $this->assertSame(200, $this->delete($empty)['status']);
        $this->assertSame(409, $this->delete($withClasses)['status']);
    }

    public function testBearbeitenBleibtMitKlassenErlaubtUndLaesstSieUnberuehrt(): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29');
        $this->createClass($id);

        $r = $this->update($id, ['starts_on' => '2091-09-01', 'ends_on' => '2092-07-31']);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame([$id], array_unique(array_column($this->app->classes(), 'school_year_id')));
    }

    // --- Rechte und Modulpruefung ---

    public function testBearbeitenUndLoeschenBrauchenSchoolYearsManageSonst403Forbidden(): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29');

        foreach ([self::NOBODY, self::VIEWER] as $sub) {
            $u = $this->update($id, ['starts_on' => '2091-09-01', 'ends_on' => '2092-07-31'], $sub);
            $d = $this->delete($id, $sub);
            $this->assertSame(403, $u['status'], $sub);
            $this->assertSame('forbidden', $u['json']['code'], $sub);
            $this->assertSame(403, $d['status'], $sub);
            $this->assertSame('forbidden', $d['json']['code'], $sub);
        }
        $this->assertSame('2090-09-14', $this->app->schoolYears()[0]['starts_on'], 'ohne Recht wird nichts geaendert');
        $this->assertSame([], $this->audit('school_year.updated'));

        $this->assertSame(200, $this->update($id, ['starts_on' => '2091-09-01', 'ends_on' => '2092-07-31'], self::MANAGER)['status']);
        $this->assertSame(200, $this->delete($id, self::MANAGER)['status']);
    }

    public function testOhneTokenKommt401(): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29');

        $this->assertSame(401, $this->app->request('POST', self::URI . "/$id/update", [], ['starts_on' => '2091-09-01', 'ends_on' => '2092-07-31'])['status']);
        $this->assertSame(401, $this->app->request('POST', self::URI . "/$id/delete")['status']);
    }

    public function testModulpruefungLaeuftVorDerRechtepruefung(): void
    {
        // Dieselben Routen unter dem Modul `observe` (ohne Freigabe): tests/Support/module-probe-routes.php.
        $this->app->resetModules();
        $probe = '/api/test/observe-school-years';
        $id = $this->createYear('2090-09-14', '2091-07-29');

        foreach ([self::ADMIN, self::NOBODY] as $sub) {
            $u = $this->update($id, ['starts_on' => '2091-09-01', 'ends_on' => '2092-07-31'], $sub, $probe);
            $d = $this->delete($id, $sub, $probe);
            $this->assertSame('module_disabled', $u['json']['code'], $sub);
            $this->assertSame('module_disabled', $d['json']['code'], $sub);
        }
        $this->assertCount(1, $this->app->schoolYears());
        $this->assertSame('2090-09-14', $this->app->schoolYears()[0]['starts_on']);
    }

    // --- Erweiterbare Pruefung ---

    // Die Test-Pruefung (tests/Support/module-probe-routes.php) meldet sich wie ein spaeteres Modul an und erhebt
    // Einwand gegen jedes Schuljahr, das im Jahr 2095 beginnt.

    public function testEinePruefungEinesAnderenModulsKannAendernUndLoeschenAblehnen(): void
    {
        $blocked = $this->createYear('2095-09-14', '2096-07-29');
        $free = $this->createYear('2090-09-14', '2091-07-29');

        $update = $this->update($blocked, ['starts_on' => '2095-09-15', 'ends_on' => '2096-07-29']);
        $delete = $this->delete($blocked);

        $this->assertSame(409, $update['status'], $update['body']);
        $this->assertSame('test_update_blocked', $update['json']['code']);
        $this->assertSame('Test: Aendern gesperrt.', $update['json']['error']);
        $this->assertSame(409, $delete['status'], $delete['body']);
        $this->assertSame('test_delete_blocked', $delete['json']['code']);
        $this->assertSame('2095-09-14', $this->app->schoolYears()[1]['starts_on'], 'der Einwand verhindert die Aenderung');
        $this->assertCount(2, $this->app->schoolYears(), 'der Einwand verhindert das Loeschen');
        $this->assertSame([], $this->audit('school_year.updated'));
        $this->assertSame([], $this->audit('school_year.deleted'));

        $this->assertSame(200, $this->update($free, ['starts_on' => '2090-09-15', 'ends_on' => '2091-07-29'])['status'], 'ohne Einwand laeuft es durch');
        $this->assertSame(200, $this->delete($free)['status']);
    }

    public function testDieKlassenPruefungLaeuftNebenDerTestPruefungUndGewinntInDerReihenfolgeDerAnmeldung(): void
    {
        $id = $this->createYear('2095-09-14', '2096-07-29');
        $this->createClass($id);

        $delete = $this->delete($id);

        $this->assertSame('school_year_has_classes', $delete['json']['code'], 'die Klassen sind zuerst angemeldet');
    }

    public function testZweiGleichzeitigeBearbeitungenInEineUeberschneidungSetzenSichNichtBeideDurch(): void
    {
        $a = $this->createYear('2090-09-01', '2091-07-31');
        $b = $this->createYear('2093-09-01', '2094-07-31');
        $call = fn (string $id, string $start, string $end): array => ['POST', self::URI . "/$id/update", $this->headers(self::ADMIN), ['starts_on' => $start, 'ends_on' => $end]];

        $responses = $this->app->requestConcurrently([$call($a, '2091-09-01', '2092-07-31'), $call($b, '2091-12-01', '2092-11-30')]);

        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
    }
}
