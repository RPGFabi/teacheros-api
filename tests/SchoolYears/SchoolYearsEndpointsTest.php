<?php

declare(strict_types=1);

namespace TeacherOS\Tests\SchoolYears;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Schuljahre ansehen und anlegen (Ticket #54) ueber HTTP gegen php-cgi + Test-DB:
 * GET /api/school-years (`school_years.view`), POST /api/school-years (`school_years.manage`).
 */
final class SchoolYearsEndpointsTest extends TestCase
{
    private const ADMIN = '54545454-5454-4545-8545-545454545454';
    private const VIEWER = '55555555-5555-4555-8555-555555555555';
    private const MANAGER = '56565656-5656-4656-8656-565656565656';
    private const NOBODY = '57575757-5757-4757-8757-575757575757';
    private const URI = '/api/school-years';

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

    private function list(string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->app->request('GET', $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)]);
    }

    private function create(array $body, string $sub = self::ADMIN, string $uri = self::URI): array
    {
        return $this->app->request('POST', $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)], $body);
    }

    private function createYear(string $start, string $end): array
    {
        return $this->create(['starts_on' => $start, 'ends_on' => $end]);
    }

    // --- Rechte ---

    public function testKatalogFuehrtDieGruppeStammdatenMitBeidenRechten(): void
    {
        $r = $this->app->request('GET', '/api/permissions', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::ADMIN)]);

        $byKey = array_column($r['json']['permissions'], null, 'key');
        $this->assertSame(['key' => 'school_years.view', 'name' => 'Schuljahre ansehen', 'group' => 'Stammdaten'], $byKey['school_years.view']);
        $this->assertSame(['key' => 'school_years.manage', 'name' => 'Schuljahre verwalten', 'group' => 'Stammdaten'], $byKey['school_years.manage']);
    }

    public function testKeineStandardrolleHatEinSchuljahrRechtVoreingestellt(): void
    {
        $roles = $this->app->request('GET', '/api/roles', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::ADMIN)])['json']['roles'];

        foreach ($roles as $role) {
            if ($role['key'] === 'schuladmin') {
                continue;
            }
            $this->assertNotContains('school_years.view', $role['permissions'], $role['key']);
            $this->assertNotContains('school_years.manage', $role['permissions'], $role['key']);
        }
    }

    public function testSchuladminHatBeideRechteUeberDieWildcard(): void
    {
        $me = $this->app->request('GET', '/api/me', ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::ADMIN)])['json'];

        $this->assertContains('school_years.view', $me['permissions']);
        $this->assertContains('school_years.manage', $me['permissions']);
    }

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('GET', self::URI)['status']);
        $this->assertSame(401, $this->app->request('POST', self::URI, [], ['starts_on' => '2026-09-14', 'ends_on' => '2027-07-29'])['status']);
    }

    public function testLesenBrauchtSchoolYearsViewSonst403Forbidden(): void
    {
        foreach ([self::NOBODY, self::MANAGER] as $sub) {
            $r = $this->list($sub);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('forbidden', $r['json']['code'], $sub);
        }
        $this->assertSame(200, $this->list(self::VIEWER)['status']);
    }

    public function testAnlegenBrauchtSchoolYearsManageSonst403Forbidden(): void
    {
        foreach ([self::NOBODY, self::VIEWER] as $sub) {
            $r = $this->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-07-29'], $sub);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('forbidden', $r['json']['code'], $sub);
        }
        $this->assertSame([], $this->app->schoolYears(), 'ohne Recht wird nichts angelegt');
        $this->assertSame(201, $this->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-07-29'], self::MANAGER)['status']);
    }

    public function testModulpruefungLaeuftVorDerRechtepruefung(): void
    {
        // Dieselben Routen, aber unter dem Modul `observe` (ohne Freigabe) eingehaengt: tests/Support/module-probe-routes.php.
        $this->app->resetModules();
        $probe = '/api/test/observe-school-years';

        $admin = $this->list(self::ADMIN, $probe);
        $this->assertSame(403, $admin['status']);
        $this->assertSame('module_disabled', $admin['json']['code']);

        $nobody = $this->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-07-29'], self::NOBODY, $probe);
        $this->assertSame(403, $nobody['status']);
        $this->assertSame('module_disabled', $nobody['json']['code'], 'der Grund ist das Modul, nicht das fehlende Recht');
        $this->assertSame([], $this->app->schoolYears());

        $this->app->insertEntitlement('observe', true, gmdate('Y-m-d H:i:s.u', time() - 3600));
        $this->assertSame(403, $this->list(self::NOBODY, $probe)['status']);
        $this->assertSame('forbidden', $this->list(self::NOBODY, $probe)['json']['code'], 'eine Freigabe gewaehrt kein Recht');
        $this->assertSame(200, $this->list(self::ADMIN, $probe)['status']);
    }

    public function testRoutenUnterCoreBrauchenKeineModulfreigabe(): void
    {
        $this->app->resetModules();

        $this->assertSame(200, $this->list()['status']);
    }

    // --- Anlegen ---

    public function testAnlegenLiefert201MitKennungBezeichnungUndAbgeleitetemStatus(): void
    {
        $r = $this->createYear('2090-09-14', '2091-07-29');

        $this->assertSame(201, $r['status'], $r['body']);
        $year = $r['json']['school_year'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $year['id']);
        $this->assertSame('2090/91', $year['name']);
        $this->assertSame('2090-09-14', $year['starts_on']);
        $this->assertSame('2091-07-29', $year['ends_on']);
        $this->assertSame('running', $year['status'], 'heute liegt in einer Luecke davor: das naechste kommende Schuljahr laeuft');
        $this->assertCount(1, $this->app->schoolYears());
    }

    public function testBezeichnungIstNichtEingebbarUndWirdAusDemZeitraumErzeugt(): void
    {
        $r = $this->create(['name' => 'Mein Jahr', 'starts_on' => '2090-09-14', 'ends_on' => '2091-07-29']);

        $this->assertSame(201, $r['status']);
        $this->assertSame('2090/91', $r['json']['school_year']['name']);
    }

    public function testAnlegenSchreibtAuditEintragMitKennungUndBezeichnung(): void
    {
        $id = $this->createYear('2090-09-14', '2091-07-29')['json']['school_year']['id'];

        $entries = array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'school_year.created'));
        $this->assertCount(1, $entries);
        $this->assertSame('school_year', $entries[0]['object_type']);
        $this->assertSame($id, $entries[0]['object_id']);
        $this->assertSame('account', $entries[0]['actor_type']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['name' => '2090/91'], json_decode($entries[0]['context'], true));
    }

    public function testUngueltigeEingabeWird400OderEndeFalsch422AbgelehntOhneAuditEintrag(): void
    {
        $cases = [
            [400, 'invalid_request', ['starts_on' => '14.09.2090', 'ends_on' => '2091-07-29']],
            [400, 'invalid_request', ['starts_on' => '2090-09-14']],
            [400, 'invalid_request', []],
            [422, 'invalid_period', ['starts_on' => '2090-09-14', 'ends_on' => '2090-12-31']],
            [422, 'invalid_period', ['starts_on' => '2090-09-14', 'ends_on' => '2092-01-01']],
            [422, 'invalid_period', ['starts_on' => '2090-09-14', 'ends_on' => '2090-07-29']],
        ];
        foreach ($cases as [$status, $code, $body]) {
            $r = $this->create($body);
            $this->assertSame($status, $r['status'], json_encode($body));
            $this->assertSame($code, $r['json']['code'], json_encode($body));
            $this->assertNotSame('', $r['json']['error']);
        }
        $this->assertSame([], $this->app->schoolYears());
        $this->assertSame([], $this->app->auditLog());
    }

    // --- Ueberschneidung ---

    /** @return iterable<string,array{string,string}> */
    public static function ueberschneidungen(): iterable
    {
        yield 'gleicher Zeitraum' => ['2090-09-14', '2091-07-29'];
        yield 'Beginn im bestehenden Schuljahr' => ['2090-12-01', '2091-12-31'];
        yield 'Ende im bestehenden Schuljahr' => ['2089-09-01', '2090-10-01'];
        yield 'umschliesst das bestehende' => ['2090-01-01', '2091-12-31'];
        yield 'liegt im bestehenden' => ['2090-10-01', '2091-06-01'];
        yield 'neuer Beginn am letzten Tag des bestehenden' => ['2091-07-29', '2092-07-31'];
        yield 'neues Ende am ersten Tag des bestehenden' => ['2089-09-01', '2090-09-14'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ueberschneidungen')]
    public function testUeberschneidungMitBestehendemSchuljahrWird409Abgelehnt(string $start, string $end): void
    {
        $this->createYear('2090-09-14', '2091-07-29');

        $r = $this->createYear($start, $end);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('school_year_overlap', $r['json']['code']);
        $this->assertCount(1, $this->app->schoolYears());
        $this->assertCount(1, $this->app->auditLog(), 'nur der Eintrag des ersten Schuljahres');
    }

    public function testLueckenSindErlaubtAuchLueckeVonEinemTagUndLueckeDavorUndDanach(): void
    {
        $this->createYear('2090-09-14', '2091-07-29');

        $this->assertSame(201, $this->createYear('2091-07-30', '2092-07-31')['status'], 'direkt anschliessend');
        $this->assertSame(201, $this->createYear('2088-09-01', '2089-07-31')['status'], 'mit Luecke davor');
        $this->assertSame(201, $this->createYear('2093-01-01', '2094-12-31')['status'], 'mit Luecke danach');
    }

    public function testZweiGleichzeitigeUeberschneidendeAnfragenLegenNurEinSchuljahrAn(): void
    {
        $call = fn (string $start): array => ['POST', self::URI, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::ADMIN)], ['starts_on' => $start, 'ends_on' => '2091-07-29']];

        $responses = $this->app->requestConcurrently([$call('2090-09-01'), $call('2090-09-14'), $call('2090-10-01')]);

        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409, 409], $statuses);
        $this->assertCount(1, $this->app->schoolYears());
    }

    // --- Lesen ---

    public function testListeIstLeerOhneSchuljahre(): void
    {
        $r = $this->list(self::VIEWER);

        $this->assertSame(200, $r['status']);
        $this->assertSame(['school_years' => []], $r['json']);
    }

    public function testListeIstNachBeginnSortiertUnabhaengigVonDerAnlegereihenfolge(): void
    {
        $this->createYear('2092-09-01', '2093-07-31');
        $this->createYear('2090-09-01', '2091-07-31');
        $this->createYear('2091-09-01', '2092-07-31');

        $years = $this->list(self::VIEWER)['json']['school_years'];

        $this->assertSame(['2090/91', '2091/92', '2092/93'], array_column($years, 'name'));
        $this->assertSame(['id', 'name', 'starts_on', 'ends_on', 'status'], array_keys($years[0]));
    }

    public function testStatusFolgtDemHeutigenDatumHoechstensEinSchuljahrLaeuft(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $runningStart = $today->modify('-30 days');
        $runningEnd = ((int) $runningStart->format('Y') + 1) . '-07-31';
        $this->createYear('2000-09-01', '2001-07-31');
        $this->createYear($runningStart->format('Y-m-d'), $runningEnd);
        $this->createYear('2090-09-01', '2091-07-31');

        $byName = array_column($this->list(self::VIEWER)['json']['school_years'], 'status', 'name');

        $this->assertSame(
            ['2000/01' => 'past', $runningStart->format('Y') . '/' . substr($runningEnd, 2, 2) => 'running', '2090/91' => 'upcoming'],
            $byName,
        );
    }

    public function testHeuteInEinerLueckeLaeuftDasNaechsteKommendeSchuljahr(): void
    {
        $this->createYear('2000-09-01', '2001-07-31');
        $this->createYear('2090-09-01', '2091-07-31');
        $this->createYear('2091-09-01', '2092-07-31');

        $byName = array_column($this->list(self::VIEWER)['json']['school_years'], 'status', 'name');

        $this->assertSame(['2000/01' => 'past', '2090/91' => 'running', '2091/92' => 'upcoming'], $byName);
    }

    // --- Migration ---

    public function testMigrationIstGefahrlosWiederholbar(): void
    {
        $this->createYear('2090-09-14', '2091-07-29');

        $this->app->rerunMigrations('0013_school_years.php');

        $this->assertCount(1, $this->app->schoolYears(), 'bestehende Schuljahre bleiben erhalten');
    }
}
