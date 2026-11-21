<?php

declare(strict_types=1);

namespace TeacherOS\Tests\ClassLeaders;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Klassenleitung (Ticket #68) ueber HTTP gegen php-cgi + Test-DB:
 * GET /api/class-leaders?class_id= (`classes.view`), POST /api/class-leaders, POST .../:id/end, POST .../:id/delete
 * (`teacher_assignments.manage`). Die waehlbaren Lehrkraefte liefert unveraendert GET /api/teacher-assignments/teachers.
 * Die Regeln (Zeitraum, Ueberschneidung, Beenden, Loeschen) sind die der Lehrkraft-Zuordnung (#67, `AssignmentRules`); hier
 * steht, was an der Klasse haengt. Das Schuljahr liegt 2090/91, also in der Zukunft der echten Uhr; fuer "heute"-abhaengige
 * Faelle haengt dieselbe Routen-Gruppe unter einer Test-Uhr mit festem Tag (tests/Support/module-probe-routes.php, TEST_TODAY).
 */
final class ClassLeadersEndpointsTest extends TestCase
{
    private const ADMIN = '68686868-0000-4568-8568-000000000001';
    private const VIEWER = '68686868-0000-4568-8568-000000000002';
    private const MANAGER = '68686868-0000-4568-8568-000000000003';
    private const NOBODY = '68686868-0000-4568-8568-000000000004';
    private const TEACHER_A = '68686868-0000-4568-8568-0000000000a1';
    private const TEACHER_B = '68686868-0000-4568-8568-0000000000b2';
    private const TEACHER_C = '68686868-0000-4568-8568-0000000000c3';
    private const URI = '/api/class-leaders';
    private const DATED = '/api/test/dated-class-leaders';
    private const TODAY = '2090-11-21';

    private HttpTestApp $app;
    private string $year;
    private string $class;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->resetSchoolYears();
        $this->app->resetSchoolSettings();
        foreach ([self::ADMIN => null, self::VIEWER => 'classes.view', self::MANAGER => 'teacher_assignments.manage', self::NOBODY => null] as $sub => $permission) {
            $this->app->createAccount($sub, 'active');
            if ($permission !== null) {
                $this->app->grantPermission($sub, $permission);
            }
        }
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        foreach ([self::TEACHER_A, self::TEACHER_B, self::TEACHER_C] as $i => $teacher) {
            $this->app->createAccount($teacher, 'active', "leitung{$i}@example.test", "leitung{$i}");
            $this->app->assignRole($teacher, 'lehrkraft');
        }
        $this->year = $this->post('/api/school-years', ['starts_on' => '2090-09-01', 'ends_on' => '2091-07-31'])['json']['school_year']['id'];
        $this->class = $this->post('/api/classes', ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7a'])['json']['class']['id'];
    }

    protected function tearDown(): void
    {
        // Die Modulpruefungs-Tests geben das Modul `observe` frei; andere Testklassen erwarten es ungenehmigt.
        $this->app->resetModules();
    }

    /** @return array{status:int, body:string, json:?array} */
    private function request(string $method, string $uri, ?array $body = null, string $sub = self::ADMIN, ?string $today = null): array
    {
        $env = ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)] + ($today === null ? [] : ['TEST_TODAY' => $today]);
        return $this->app->request($method, $uri, $env, $body);
    }

    private function post(string $uri, array $body = [], string $sub = self::ADMIN): array
    {
        return $this->request('POST', $uri, $body, $sub);
    }

    /** @return array{status:int, body:string, json:?array} */
    private function assign(string $teacher, ?string $from = null, ?string $until = null, string $sub = self::ADMIN, string $uri = self::URI, ?string $today = null, ?string $class = null): array
    {
        $body = ['class_id' => $class ?? $this->class, 'teacher_id' => $teacher];
        if ($from !== null) {
            $body['starts_on'] = $from;
        }
        if ($until !== null) {
            $body['ends_on'] = $until;
        }
        return $this->request('POST', $uri, $body, $sub, $today);
    }

    private function created(string $teacher, ?string $from = null, ?string $until = null, string $uri = self::URI, ?string $today = null): string
    {
        $r = $this->assign($teacher, $from, $until, self::ADMIN, $uri, $today);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json']['class_leader']['id'];
    }

    private function list(string $sub = self::ADMIN, string $uri = self::URI, ?string $today = null, ?string $class = null): array
    {
        return $this->request('GET', $uri . '?class_id=' . ($class ?? $this->class), null, $sub, $today);
    }

    private function rows(): array
    {
        return $this->app->pdo()->query('SELECT * FROM `class_leaders` ORDER BY `created_at`, `id`')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function audit(string $action): array
    {
        return array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === $action));
    }

    // --- Rechte und Modul ---

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('GET', self::URI . '?class_id=' . $this->class)['status']);
        $this->assertSame(401, $this->app->request('POST', self::URI, [], [])['status']);
        $this->assertSame(401, $this->app->request('POST', self::URI . '/x/end', [], [])['status']);
        $this->assertSame(401, $this->app->request('POST', self::URI . '/x/delete', [], [])['status']);
    }

    public function testLesenBrauchtClassesViewVerwaltenBrauchtTeacherAssignmentsManage(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-10-01');

        foreach ([self::NOBODY, self::MANAGER] as $sub) {
            $r = $this->list($sub);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('forbidden', $r['json']['code'], $sub);
        }
        $this->assertSame(200, $this->list(self::VIEWER)['status']);

        $writes = [
            fn (string $sub): array => $this->assign(self::TEACHER_B, '2090-10-01', null, $sub),
            fn (string $sub): array => $this->request('POST', self::URI . '/' . $id . '/end', ['ends_on' => '2090-12-01'], $sub),
            fn (string $sub): array => $this->request('POST', self::URI . '/' . $id . '/delete', [], $sub),
        ];
        foreach ($writes as $i => $write) {
            foreach ([self::NOBODY, self::VIEWER] as $sub) {
                $r = $write($sub);
                $this->assertSame(403, $r['status'], "$i $sub");
                $this->assertSame('forbidden', $r['json']['code'], "$i $sub");
            }
        }
        $this->assertCount(1, $this->rows(), 'ohne Recht aendert sich nichts');
        $this->assertSame(201, $this->assign(self::TEACHER_B, '2090-10-01', null, self::MANAGER)['status'], 'teacher_assignments.manage genuegt zum Zuordnen');
    }

    public function testModulpruefungLaeuftVorDerRechtepruefung(): void
    {
        $this->app->resetModules();
        $probe = '/api/test/observe-class-leaders';

        foreach ([$this->list(self::ADMIN, $probe), $this->list(self::NOBODY, $probe), $this->assign(self::TEACHER_A, null, null, self::NOBODY, $probe), $this->request('POST', $probe . '/x/end', [], self::NOBODY), $this->request('POST', $probe . '/x/delete', [], self::NOBODY)] as $i => $r) {
            $this->assertSame(403, $r['status'], (string) $i);
            $this->assertSame('module_disabled', $r['json']['code'], (string) $i);
        }
        $this->assertSame([], $this->rows());

        $this->app->insertEntitlement('observe', true, gmdate('Y-m-d H:i:s.u', time() - 3600));
        $this->assertSame('forbidden', $this->list(self::NOBODY, $probe)['json']['code']);
        $this->assertSame(200, $this->list(self::ADMIN, $probe)['status']);
    }

    // --- Anlegen ---

    public function testAnlegenOhneZeitraumGiltBisSchuljahresendeAbSchuljahresanfang(): void
    {
        $r = $this->assign(self::TEACHER_A);

        $this->assertSame(201, $r['status'], $r['body']);
        $a = $r['json']['class_leader'];
        $this->assertSame($this->class, $a['class_id']);
        $this->assertSame(['id' => self::TEACHER_A, 'login_name' => 'leitung0', 'email' => 'leitung0@example.test', 'status' => 'active'], $a['teacher']);
        $this->assertSame('2090-09-01', $a['starts_on']);
        $this->assertNull($a['ends_on']);
        $this->assertSame('2091-07-31', $a['last_day']);
        $this->assertSame('upcoming', $a['status']);
        $row = $this->rows()[0];
        $this->assertSame(['2090-09-01', null], [$row['valid_from'], $row['valid_until']]);
    }

    public function testDasEndeIstEinschliesslichUndGespeichertWirdDieExklusiveObergrenze(): void
    {
        $a = $this->assign(self::TEACHER_A, '2090-10-01', '2090-12-31')['json']['class_leader'];

        $this->assertSame(['2090-10-01', '2090-12-31', '2090-12-31'], [$a['starts_on'], $a['ends_on'], $a['last_day']]);
        $row = $this->rows()[0];
        $this->assertSame(['2090-10-01', '2091-01-01'], [$row['valid_from'], $row['valid_until']]);
    }

    public function testZeitraumAusserhalbDesSchuljahresDerKlasseIst422(): void
    {
        foreach ([['2090-08-31', null], ['2091-08-01', null], [null, '2091-08-01']] as [$from, $until]) {
            $r = $this->assign(self::TEACHER_A, $from, $until);
            $this->assertSame(422, $r['status'], $r['body']);
            $this->assertSame('period_outside_school_year', $r['json']['code']);
        }
        $this->assertSame('invalid_period', $this->assign(self::TEACHER_A, '2090-12-01', '2090-11-30')['json']['code']);
        $this->assertSame([], $this->rows());
    }

    public function testUngueltigeEingabeIst400(): void
    {
        foreach ([['class_id' => $this->class, 'starts_on' => '2090-10-01'], ['teacher_id' => self::TEACHER_A], ['class_id' => $this->class, 'teacher_id' => 5],
            ['class_id' => $this->class, 'teacher_id' => self::TEACHER_A, 'starts_on' => '01.10.2090'], ['class_id' => $this->class, 'teacher_id' => self::TEACHER_A, 'ends_on' => '2090-02-30']] as $i => $body) {
            $r = $this->post(self::URI, $body);
            $this->assertSame(400, $r['status'], "$i " . $r['body']);
            $this->assertSame('invalid_request', $r['json']['code'], (string) $i);
        }
        $this->assertSame([], $this->rows());
    }

    public function testUnbekannteKlasseIst404(): void
    {
        $unknown = '00000000-0000-4000-8000-000000000000';

        $r = $this->assign(self::TEACHER_A, null, null, self::ADMIN, self::URI, null, $unknown);

        $this->assertSame([404, 'class_not_found'], [$r['status'], $r['json']['code']]);
        $this->assertSame('class_not_found', $this->list(self::ADMIN, self::URI, null, $unknown)['json']['code']);
        $this->assertSame(400, $this->request('GET', self::URI)['status'], 'ohne Klasse');
    }

    public function testNurLehrkraefteOhneDeaktivierteSindWaehlbar(): void
    {
        $this->app->createAccount('68686868-0000-4568-8568-0000000000d4', 'inactive', 'gesperrt@example.test', 'gesperrt');
        $this->app->assignRole('68686868-0000-4568-8568-0000000000d4', 'lehrkraft');
        $this->app->createAccount('68686868-0000-4568-8568-0000000000e5', 'active', 'sekretariat@example.test', 'sekretariat');
        $this->app->assignRole('68686868-0000-4568-8568-0000000000e5', 'sekretariat');
        $this->app->createAccount('68686868-0000-4568-8568-0000000000f6', 'invited', 'neu@example.test', 'neu');
        $this->app->assignRole('68686868-0000-4568-8568-0000000000f6', 'lehrkraft');

        foreach (['68686868-0000-4568-8568-0000000000d4', '68686868-0000-4568-8568-0000000000e5', self::ADMIN, '00000000-0000-4000-8000-000000000000'] as $notTeacher) {
            $r = $this->assign($notTeacher);
            $this->assertSame([409, 'teacher_not_selectable'], [$r['status'], $r['json']['code']], $notTeacher);
        }
        $this->assertSame(201, $this->assign('68686868-0000-4568-8568-0000000000f6')['status'], 'eingeladen ist zuordenbar');
        $this->assertCount(1, $this->rows());
    }

    public function testRolleUndStatusWerdenNurBeimAnlegenGeprueftDieHistorieBleibt(): void
    {
        $id = $this->created(self::TEACHER_A);

        $this->app->setAccountStatus(self::TEACHER_A, 'inactive');
        $this->app->pdo()->exec("DELETE FROM `permission_user_roles` WHERE `user_uuid` = '" . self::TEACHER_A . "'");

        $list = $this->list()['json']['class_leaders'];
        $this->assertSame($id, $list[0]['id']);
        $this->assertSame('inactive', $list[0]['teacher']['status']);
        $this->assertSame(200, $this->post(self::URI . '/' . $id . '/end', ['ends_on' => '2091-06-01'])['status'], 'Beenden geht weiter');
    }

    public function testAnlegenSchreibtAuditOhnePersonenbezogeneAngaben(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-10-01', '2090-12-31');

        $entries = $this->audit('class_lead.created');
        $this->assertCount(1, $entries);
        $this->assertSame(['class_lead', $id, self::ADMIN], [$entries[0]['object_type'], $entries[0]['object_id'], $entries[0]['actor_account_id']]);
        $this->assertSame(
            ['class_id' => $this->class, 'teacher_id' => self::TEACHER_A, 'starts_on' => '2090-10-01', 'ends_on' => '2090-12-31'],
            json_decode($entries[0]['context'], true),
        );
        $this->assertStringNotContainsString('example.test', $entries[0]['context']);
    }

    // --- Lesen ---

    public function testListeNachBeginnDannKennungMitStatusNurDerKlasse(): void
    {
        $other = $this->post('/api/classes', ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7b'])['json']['class']['id'];
        $this->assign(self::TEACHER_C, null, null, self::ADMIN, self::URI, null, $other);
        $late = $this->created(self::TEACHER_B, '2091-01-01');
        $early = $this->created(self::TEACHER_A, '2090-09-01', '2090-12-31');

        $r = $this->list(self::VIEWER);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame([$early, $late], array_column($r['json']['class_leaders'], 'id'));
    }

    public function testStatusFolgtDerFestenUhr(): void
    {
        $this->created(self::TEACHER_A, '2090-09-01', '2090-11-20', self::DATED, self::TODAY);
        $this->created(self::TEACHER_B, '2090-11-21', null, self::DATED, self::TODAY);
        $this->created(self::TEACHER_C, '2090-11-22', null, self::DATED, self::TODAY);

        $statuses = array_column($this->list(self::ADMIN, self::DATED, self::TODAY)['json']['class_leaders'], 'status');

        $this->assertSame(['ended', 'running', 'upcoming'], $statuses);
    }

    // --- Ueberschneidung und Obergrenze ---

    public function testDieselbeLehrkraftNichtMitUeberschneidendemZeitraumNacheinanderSchon(): void
    {
        $this->created(self::TEACHER_A, '2090-09-01', '2090-12-31');

        $r = $this->assign(self::TEACHER_A, '2090-12-31', '2091-02-01');
        $this->assertSame([409, 'assignment_overlap'], [$r['status'], $r['json']['code']], $r['body']);

        $this->assertSame(201, $this->assign(self::TEACHER_A, '2091-01-01', '2091-02-01')['status']);
        $this->assertCount(2, $this->rows());
    }

    public function testDerselbeZeitraumAnEinerAnderenKlasseIstKeineUeberschneidung(): void
    {
        $other = $this->post('/api/classes', ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7b'])['json']['class']['id'];
        $this->created(self::TEACHER_A);

        $this->assertSame(201, $this->assign(self::TEACHER_A, null, null, self::ADMIN, self::URI, null, $other)['status']);
    }

    public function testDieObergrenzeAusDerSchuleinstellungGiltAnJedemTag(): void
    {
        // Standard der Schuleinstellung: 2 Klassenleitungen je Klasse.
        $this->created(self::TEACHER_A);
        $this->created(self::TEACHER_B, '2090-09-01', '2090-12-31');

        $r = $this->assign(self::TEACHER_C, '2090-12-15');
        $this->assertSame([409, 'assignment_limit_reached'], [$r['status'], $r['json']['code']], $r['body']);

        $this->assertSame(201, $this->assign(self::TEACHER_C, '2091-01-01')['status'], 'nacheinander');

        $this->assertSame(200, $this->post('/api/school-settings/update', ['max_class_leaders_per_class' => 3])['status']);
        $this->assertSame(201, $this->assign(self::TEACHER_C, '2090-09-01', '2090-10-31')['status'], 'nach Erhoehung der Obergrenze');
    }

    public function testDieObergrenzeDerFachgruppenGiltFuerKlassenleitungenNicht(): void
    {
        $this->assertSame(200, $this->post('/api/school-settings/update', ['max_teachers_per_teaching_group' => 1])['status']);

        $this->created(self::TEACHER_A);
        $this->assertSame(201, $this->assign(self::TEACHER_B)['status']);
    }

    public function testEineVerschaerfteObergrenzeLaesstBestehendesUnberuehrt(): void
    {
        $this->created(self::TEACHER_A);
        $this->created(self::TEACHER_B);
        $this->assertSame(200, $this->post('/api/school-settings/update', ['max_class_leaders_per_class' => 1])['status']);

        $this->assertCount(2, $this->list()['json']['class_leaders']);
        $this->assertSame('assignment_limit_reached', $this->assign(self::TEACHER_C)['json']['code']);
    }

    // --- Beenden ---

    public function testEineOffeneKlassenleitungBeendenSetztDasEndeUndBehaeltDenBeginn(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', null, self::DATED, self::TODAY);

        $r = $this->request('POST', self::DATED . '/' . $id . '/end', ['ends_on' => '2090-12-18'], self::ADMIN, self::TODAY);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['2090-09-01', '2090-12-18'], [$r['json']['class_leader']['starts_on'], $r['json']['class_leader']['ends_on']]);
        $row = $this->rows()[0];
        $this->assertSame(['2090-09-01', '2090-12-19'], [$row['valid_from'], $row['valid_until']]);
        $ended = $this->audit('class_lead.ended');
        $this->assertCount(1, $ended);
        $this->assertSame(['class_id' => $this->class, 'teacher_id' => self::TEACHER_A, 'starts_on' => '2090-09-01', 'ends_on' => '2090-12-18'], json_decode($ended[0]['context'], true));
        $this->assertSame(['class_lead', $id], [$ended[0]['object_type'], $ended[0]['object_id']]);
    }

    public function testBeendenOhneAngabeBeendetHeute(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', null, self::DATED, self::TODAY);

        $r = $this->request('POST', self::DATED . '/' . $id . '/end', [], self::ADMIN, self::TODAY);

        $this->assertSame('2090-11-21', $r['json']['class_leader']['ends_on']);
    }

    public function testEinInDerZukunftLiegendesEndeLaesstSichVorverlegen(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', '2091-03-01', self::DATED, self::TODAY);

        $r = $this->request('POST', self::DATED . '/' . $id . '/end', ['ends_on' => '2090-12-18'], self::ADMIN, self::TODAY);

        $this->assertSame('2090-12-18', $r['json']['class_leader']['ends_on']);
    }

    public function testRueckwirkendBeendenUndNachtraeglichVerlaengernSindAbgelehnt(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', '2091-03-01', self::DATED, self::TODAY);

        $past = $this->request('POST', self::DATED . '/' . $id . '/end', ['ends_on' => '2090-11-20'], self::ADMIN, self::TODAY);
        $this->assertSame([409, 'assignment_applied'], [$past['status'], $past['json']['code']]);
        $later = $this->request('POST', self::DATED . '/' . $id . '/end', ['ends_on' => '2091-05-01'], self::ADMIN, self::TODAY);
        $this->assertSame([422, 'invalid_period'], [$later['status'], $later['json']['code']]);
        $this->assertSame('2091-03-02', $this->rows()[0]['valid_until']);
    }

    public function testEineBeendeteKlassenleitungLaesstSichNichtMehrBeenden(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', '2090-11-01', self::DATED, self::TODAY);

        $r = $this->request('POST', self::DATED . '/' . $id . '/end', ['ends_on' => '2090-10-01'], self::ADMIN, self::TODAY);

        $this->assertSame([409, 'assignment_ended'], [$r['status'], $r['json']['code']]);
    }

    public function testBeendenEinerUnbekanntenKlassenleitungIst404(): void
    {
        $r = $this->post(self::URI . '/00000000-0000-4000-8000-000000000000/end', ['ends_on' => '2090-10-01']);

        $this->assertSame([404, 'class_leader_not_found'], [$r['status'], $r['json']['code']]);
    }

    // --- Loeschen ---

    public function testLoeschenNurBeiBeginnInDerZukunft(): void
    {
        $future = $this->created(self::TEACHER_A, '2090-11-22', null, self::DATED, self::TODAY);
        $today = $this->created(self::TEACHER_B, '2090-11-21', null, self::DATED, self::TODAY);
        $past = $this->created(self::TEACHER_C, '2090-09-01', '2090-10-01', self::DATED, self::TODAY);

        foreach ([$today, $past] as $applied) {
            $r = $this->request('POST', self::DATED . '/' . $applied . '/delete', [], self::ADMIN, self::TODAY);
            $this->assertSame([409, 'assignment_applied'], [$r['status'], $r['json']['code']], $applied);
        }
        $this->assertCount(3, $this->rows());

        $r = $this->request('POST', self::DATED . '/' . $future . '/delete', [], self::ADMIN, self::TODAY);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['deleted' => true, 'id' => $future], $r['json']);
        $this->assertCount(2, $this->rows());
        $deleted = $this->audit('class_lead.deleted');
        $this->assertCount(1, $deleted);
        $this->assertSame(['class_lead', $future], [$deleted[0]['object_type'], $deleted[0]['object_id']]);
        $this->assertSame(['class_id' => $this->class, 'teacher_id' => self::TEACHER_A, 'starts_on' => '2090-11-22', 'ends_on' => null], json_decode($deleted[0]['context'], true));
    }

    public function testLoeschenEinerUnbekanntenKlassenleitungIst404(): void
    {
        $r = $this->post(self::URI . '/00000000-0000-4000-8000-000000000000/delete');

        $this->assertSame([404, 'class_leader_not_found'], [$r['status'], $r['json']['code']]);
    }

    // --- Schuljahr aendern (SchoolYearGuard) ---

    private function updateYear(string $from, string $until): array
    {
        return $this->post('/api/school-years/' . $this->year . '/update', ['starts_on' => $from, 'ends_on' => $until]);
    }

    public function testEinSchuljahrDasKlassenleitungenHerausfallenLiesse409OhneStilleKuerzung(): void
    {
        $this->created(self::TEACHER_A, '2090-10-01', '2091-03-31');

        foreach ([['2090-10-02', '2091-07-31'], ['2090-09-01', '2091-03-30']] as [$from, $until]) {
            $r = $this->updateYear($from, $until);
            $this->assertSame([409, 'school_year_has_class_leaders'], [$r['status'], $r['json']['code']], $r['body']);
        }
        $this->assertSame(['2090-09-01', '2091-07-31'], [$this->app->schoolYears()[0]['starts_on'], $this->app->schoolYears()[0]['ends_on']]);
        $this->assertSame('2091-04-01', $this->rows()[0]['valid_until'], 'nichts gekuerzt');
    }

    public function testEineSchuljahrAenderungOhneHerausfallendeZeitraeumeBleibtErlaubt(): void
    {
        $this->created(self::TEACHER_A, '2090-10-01', '2091-03-31');
        $this->created(self::TEACHER_B, '2090-10-02');

        $this->assertSame(200, $this->updateYear('2090-10-01', '2091-07-31')['status'], 'Beginn auf den Beginn einer Klassenleitung');
        $this->assertSame(200, $this->updateYear('2090-09-01', '2091-03-31')['status'], 'ein offenes Ende folgt dem Schuljahresende');
        $this->assertSame('2091-03-31', $this->list()['json']['class_leaders'][1]['last_day']);
    }

    public function testEinSchuljahrDessenOffeneKlassenleitungNachDemNeuenEndeBeginntIst409(): void
    {
        $this->created(self::TEACHER_A, '2091-06-01');

        $this->assertSame('school_year_has_class_leaders', $this->updateYear('2090-09-01', '2091-05-31')['json']['code']);
    }

    // --- Archivierte und verknuepfte Klasse (Ticket #57) ---

    private function archiveClass(): void
    {
        $r = $this->post('/api/classes/' . $this->class . '/archive');
        $this->assertSame(200, $r['status'], $r['body']);
    }

    public function testEinerArchiviertenKlasseWirdKeineKlassenleitungNeuZugeordnet(): void
    {
        $this->archiveClass();

        $r = $this->assign(self::TEACHER_A);

        $this->assertSame([409, 'class_archived'], [$r['status'], $r['json']['code']], $r['body']);
        $this->assertSame([], $this->rows());
    }

    public function testArchivierenBeendetLaufendeKlassenleitungenNichtSieLassenSichWeiterBeendenUndLesen(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', null, self::DATED, self::TODAY);

        $this->archiveClass();

        $row = $this->rows()[0];
        $this->assertSame(['2090-09-01', null], [$row['valid_from'], $row['valid_until']], 'unveraendert');
        $this->assertCount(1, $this->list(self::ADMIN, self::DATED, self::TODAY)['json']['class_leaders']);
        $r = $this->request('POST', self::DATED . '/' . $id . '/end', ['ends_on' => '2090-12-18'], self::ADMIN, self::TODAY);
        $this->assertSame(200, $r['status'], $r['body']);
    }

    public function testNachWiederherstellenIstDieKlasseWiederWaehlbar(): void
    {
        $this->archiveClass();
        $this->assertSame(200, $this->post('/api/classes/' . $this->class . '/restore')['status']);

        $this->assertSame(201, $this->assign(self::TEACHER_A)['status']);
    }

    public function testEineKlasseMitKlassenleitungGiltAlsVerknuepftLoeschenWirdAufArchivierenVerwiesen(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', '2090-10-01', self::DATED, self::TODAY);

        $r = $this->post('/api/classes/' . $this->class . '/delete');

        $this->assertSame([409, 'class_linked'], [$r['status'], $r['json']['code']], $r['body']);
        $this->assertStringContainsString('Klassenleitung', $r['json']['error']);
        $this->assertStringContainsString('archivieren', $r['json']['error']);
        $this->assertCount(1, $this->app->classes());

        $this->archiveClass();
        $this->assertSame('class_linked', $this->post('/api/classes/' . $this->class . '/delete')['json']['code'], 'auch archiviert');
        $this->assertSame($id, $this->rows()[0]['id']);
    }

    public function testEineKlasseOhneKlassenleitungLaesstSichLoeschen(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-10-01');
        $this->assertSame(200, $this->post(self::URI . '/' . $id . '/delete')['status']);

        $this->assertSame(200, $this->post('/api/classes/' . $this->class . '/delete')['status']);
    }

    public function testDieMigrationIstWiederholbar(): void
    {
        $this->created(self::TEACHER_A);

        $this->app->rerunMigrations('0025_class_leaders.php');

        $this->assertCount(1, $this->rows());
    }
}
