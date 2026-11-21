<?php

declare(strict_types=1);

namespace TeacherOS\Tests\TeacherAssignments;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Lehrkraft-Zuordnung zu Fachgruppen (Ticket #67) ueber HTTP gegen php-cgi + Test-DB:
 * GET /api/teacher-assignments?teaching_group_id= (`teaching_groups.view`), GET .../teachers, POST /api/teacher-assignments,
 * POST .../:id/end, POST .../:id/delete (`teacher_assignments.manage`). Das Schuljahr liegt 2090/91, also in der Zukunft der
 * echten Uhr; fuer "heute"-abhaengige Faelle haengt dieselbe Routen-Gruppe unter einer Test-Uhr mit festem Tag
 * (tests/Support/module-probe-routes.php, Umgebungsvariable TEST_TODAY).
 */
final class TeacherAssignmentsEndpointsTest extends TestCase
{
    private const ADMIN = '67676767-0000-4567-8567-000000000001';
    private const VIEWER = '67676767-0000-4567-8567-000000000002';
    private const MANAGER = '67676767-0000-4567-8567-000000000003';
    private const NOBODY = '67676767-0000-4567-8567-000000000004';
    private const TEACHER_A = '67676767-0000-4567-8567-0000000000a1';
    private const TEACHER_B = '67676767-0000-4567-8567-0000000000b2';
    private const TEACHER_C = '67676767-0000-4567-8567-0000000000c3';
    private const URI = '/api/teacher-assignments';
    private const DATED = '/api/test/dated-teacher-assignments';
    private const TODAY = '2090-11-21';

    private HttpTestApp $app;
    private string $year;
    private string $group;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->resetSchoolYears();
        $this->app->resetSubjects();
        $this->app->resetSchoolSettings();
        foreach ([self::ADMIN => null, self::VIEWER => 'teaching_groups.view', self::MANAGER => 'teacher_assignments.manage', self::NOBODY => null] as $sub => $permission) {
            $this->app->createAccount($sub, 'active');
            if ($permission !== null) {
                $this->app->grantPermission($sub, $permission);
            }
        }
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        foreach ([self::TEACHER_A, self::TEACHER_B, self::TEACHER_C] as $i => $teacher) {
            $this->app->createAccount($teacher, 'active', "lehrkraft{$i}@example.test", "lehrkraft{$i}");
            $this->app->assignRole($teacher, 'lehrkraft');
        }
        $this->year = $this->post('/api/school-years', ['starts_on' => '2090-09-01', 'ends_on' => '2091-07-31'])['json']['school_year']['id'];
        $subject = $this->post('/api/subjects', ['code' => 'Ma', 'name' => 'Mathematik'])['json']['subject']['id'];
        $class = $this->post('/api/classes', ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7a'])['json']['class']['id'];
        $this->group = $this->post('/api/teaching-groups', ['school_year_id' => $this->year, 'subject_id' => $subject, 'name' => 'Ma 7', 'class_ids' => [$class]])['json']['teaching_group']['id'];
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
    private function assign(string $teacher, ?string $from = null, ?string $until = null, string $sub = self::ADMIN, string $uri = self::URI, ?string $today = null): array
    {
        $body = ['teaching_group_id' => $this->group, 'teacher_id' => $teacher];
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
        return $r['json']['teacher_assignment']['id'];
    }

    private function list(string $sub = self::ADMIN, string $uri = self::URI, ?string $today = null, ?string $group = null): array
    {
        return $this->request('GET', $uri . '?teaching_group_id=' . ($group ?? $this->group), null, $sub, $today);
    }

    private function rows(): array
    {
        return $this->app->pdo()->query('SELECT * FROM `teacher_assignments` ORDER BY `created_at`, `id`')->fetchAll(\PDO::FETCH_ASSOC);
    }

    // --- Rechte und Modul ---

    public function testKatalogFuehrtDasRechtInDerGruppeStammdaten(): void
    {
        $byKey = array_column($this->request('GET', '/api/permissions')['json']['permissions'], null, 'key');

        $this->assertSame(['key' => 'teacher_assignments.manage', 'name' => 'Lehrkraft-Zuordnungen verwalten', 'group' => 'Stammdaten'], $byKey['teacher_assignments.manage']);
    }

    public function testKeineStandardrolleHatDasRechtVoreingestellt(): void
    {
        foreach ($this->request('GET', '/api/roles')['json']['roles'] as $role) {
            if ($role['key'] !== 'schuladmin') {
                $this->assertNotContains('teacher_assignments.manage', $role['permissions'], $role['key']);
            }
        }
        $this->assertContains('teacher_assignments.manage', $this->request('GET', '/api/me')['json']['permissions']);
    }

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('GET', self::URI . '?teaching_group_id=' . $this->group)['status']);
        $this->assertSame(401, $this->app->request('GET', self::URI . '/teachers')['status']);
        $this->assertSame(401, $this->app->request('POST', self::URI, [], [])['status']);
        $this->assertSame(401, $this->app->request('POST', self::URI . '/x/end', [], [])['status']);
        $this->assertSame(401, $this->app->request('POST', self::URI . '/x/delete', [], [])['status']);
    }

    public function testLesenBrauchtTeachingGroupsViewVerwaltenBrauchtTeacherAssignmentsManage(): void
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
            fn (string $sub): array => $this->request('GET', self::URI . '/teachers', null, $sub),
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
        $this->assertSame(200, $writes[2](self::MANAGER)['status']);
    }

    public function testModulpruefungLaeuftVorDerRechtepruefung(): void
    {
        $this->app->resetModules();
        $probe = '/api/test/observe-teacher-assignments';

        foreach ([$this->list(self::ADMIN, $probe), $this->list(self::NOBODY, $probe), $this->assign(self::TEACHER_A, null, null, self::NOBODY, $probe), $this->request('GET', $probe . '/teachers', null, self::NOBODY), $this->request('POST', $probe . '/x/end', [], self::NOBODY), $this->request('POST', $probe . '/x/delete', [], self::NOBODY)] as $i => $r) {
            $this->assertSame(403, $r['status'], (string) $i);
            $this->assertSame('module_disabled', $r['json']['code'], (string) $i);
        }
        $this->assertSame([], $this->rows());

        $this->app->insertEntitlement('observe', true, gmdate('Y-m-d H:i:s.u', time() - 3600));
        $this->assertSame('forbidden', $this->list(self::NOBODY, $probe)['json']['code']);
        $this->assertSame(200, $this->list(self::ADMIN, $probe)['status']);
    }

    // --- Wahlbare Lehrkraefte ---

    public function testWaehlbareLehrkraeftesindKontenMitDerStandardrolleLehrkraftOhneDeaktivierte(): void
    {
        $this->app->createAccount('67676767-0000-4567-8567-0000000000d4', 'invited', 'eingeladen@example.test', 'eingeladen');
        $this->app->assignRole('67676767-0000-4567-8567-0000000000d4', 'lehrkraft');
        $this->app->createAccount('67676767-0000-4567-8567-0000000000e5', 'inactive', 'gesperrt@example.test', 'gesperrt');
        $this->app->assignRole('67676767-0000-4567-8567-0000000000e5', 'lehrkraft');
        $this->app->createAccount('67676767-0000-4567-8567-0000000000f6', 'active', 'sekretariat@example.test', 'sekretariat');
        $this->app->assignRole('67676767-0000-4567-8567-0000000000f6', 'sekretariat');

        $r = $this->request('GET', self::URI . '/teachers');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(
            [
                ['id' => '67676767-0000-4567-8567-0000000000d4', 'login_name' => 'eingeladen', 'email' => 'eingeladen@example.test', 'status' => 'invited'],
                ['id' => self::TEACHER_A, 'login_name' => 'lehrkraft0', 'email' => 'lehrkraft0@example.test', 'status' => 'active'],
                ['id' => self::TEACHER_B, 'login_name' => 'lehrkraft1', 'email' => 'lehrkraft1@example.test', 'status' => 'active'],
                ['id' => self::TEACHER_C, 'login_name' => 'lehrkraft2', 'email' => 'lehrkraft2@example.test', 'status' => 'active'],
            ],
            $r['json']['teachers'],
        );
    }

    // --- Anlegen ---

    public function testAnlegenOhneZeitraumGiltBisSchuljahresendeAbSchuljahresanfang(): void
    {
        $r = $this->assign(self::TEACHER_A);

        $this->assertSame(201, $r['status'], $r['body']);
        $a = $r['json']['teacher_assignment'];
        $this->assertSame($this->group, $a['teaching_group_id']);
        $this->assertSame(['id' => self::TEACHER_A, 'login_name' => 'lehrkraft0', 'email' => 'lehrkraft0@example.test', 'status' => 'active'], $a['teacher']);
        $this->assertSame('2090-09-01', $a['starts_on']);
        $this->assertNull($a['ends_on']);
        $this->assertSame('2091-07-31', $a['last_day']);
        $this->assertSame('upcoming', $a['status']);
        $row = $this->rows()[0];
        $this->assertSame(['2090-09-01', null], [$row['valid_from'], $row['valid_until']]);
    }

    public function testDasEndeIstEinschliesslichUndGespeichertWirdDieExklusiveObergrenze(): void
    {
        $r = $this->assign(self::TEACHER_A, '2090-10-01', '2090-12-31');

        $a = $r['json']['teacher_assignment'];
        $this->assertSame(['2090-10-01', '2090-12-31', '2090-12-31'], [$a['starts_on'], $a['ends_on'], $a['last_day']]);
        $row = $this->rows()[0];
        $this->assertSame(['2090-10-01', '2091-01-01'], [$row['valid_from'], $row['valid_until']]);
    }

    public function testZeitraumAusserhalbDesSchuljahresIst422(): void
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
        foreach ([['teaching_group_id' => $this->group, 'starts_on' => '2090-10-01'], ['teacher_id' => self::TEACHER_A], ['teaching_group_id' => $this->group, 'teacher_id' => 5],
            ['teaching_group_id' => $this->group, 'teacher_id' => self::TEACHER_A, 'starts_on' => '01.10.2090'], ['teaching_group_id' => $this->group, 'teacher_id' => self::TEACHER_A, 'ends_on' => '2090-02-30']] as $i => $body) {
            $r = $this->post(self::URI, $body);
            $this->assertSame(400, $r['status'], "$i " . $r['body']);
            $this->assertSame('invalid_request', $r['json']['code'], (string) $i);
        }
        $this->assertSame([], $this->rows());
    }

    public function testUnbekannteFachgruppeIst404(): void
    {
        $r = $this->post(self::URI, ['teaching_group_id' => '00000000-0000-4000-8000-000000000000', 'teacher_id' => self::TEACHER_A]);

        $this->assertSame(404, $r['status']);
        $this->assertSame('teaching_group_not_found', $r['json']['code']);
        $this->assertSame('teaching_group_not_found', $this->list(self::ADMIN, self::URI, null, '00000000-0000-4000-8000-000000000000')['json']['code']);
    }

    public function testNurLehrkraefteOhneDeaktivierteSindWaehlbar(): void
    {
        $this->app->createAccount('67676767-0000-4567-8567-0000000000d4', 'inactive', 'gesperrt@example.test', 'gesperrt');
        $this->app->assignRole('67676767-0000-4567-8567-0000000000d4', 'lehrkraft');
        $this->app->createAccount('67676767-0000-4567-8567-0000000000e5', 'active', 'sekretariat@example.test', 'sekretariat');
        $this->app->assignRole('67676767-0000-4567-8567-0000000000e5', 'sekretariat');
        $this->app->createAccount('67676767-0000-4567-8567-0000000000f6', 'invited', 'neu@example.test', 'neu');
        $this->app->assignRole('67676767-0000-4567-8567-0000000000f6', 'lehrkraft');

        foreach (['67676767-0000-4567-8567-0000000000d4', '67676767-0000-4567-8567-0000000000e5', self::ADMIN, '00000000-0000-4000-8000-000000000000'] as $notTeacher) {
            $r = $this->assign($notTeacher);
            $this->assertSame(409, $r['status'], $notTeacher . $r['body']);
            $this->assertSame('teacher_not_selectable', $r['json']['code'], $notTeacher);
        }
        $this->assertSame(201, $this->assign('67676767-0000-4567-8567-0000000000f6')['status'], 'eingeladen ist zuordenbar');
        $this->assertCount(1, $this->rows());
    }

    public function testRolleUndStatusWerdenNurBeimAnlegenGeprueftDieHistorieBleibt(): void
    {
        $id = $this->created(self::TEACHER_A);

        $this->app->setAccountStatus(self::TEACHER_A, 'inactive');
        $this->app->pdo()->exec("DELETE FROM `permission_user_roles` WHERE `user_uuid` = '" . self::TEACHER_A . "'");

        $list = $this->list()['json']['teacher_assignments'];
        $this->assertSame($id, $list[0]['id']);
        $this->assertSame('inactive', $list[0]['teacher']['status']);
        $this->assertSame(200, $this->post(self::URI . '/' . $id . '/end', ['ends_on' => '2091-06-01'])['status'], 'Beenden geht weiter');
    }

    public function testDieselbeLehrkraftNichtMitUeberschneidendemZeitraumNacheinanderSchon(): void
    {
        $this->created(self::TEACHER_A, '2090-09-01', '2090-12-31');

        $r = $this->assign(self::TEACHER_A, '2090-12-31', '2091-02-01');
        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('assignment_overlap', $r['json']['code']);

        $this->assertSame(201, $this->assign(self::TEACHER_A, '2091-01-01', '2091-02-01')['status']);
        $this->assertCount(2, $this->rows());
    }

    public function testDieObergrenzeAusDerSchuleinstellungGiltAnJedemTag(): void
    {
        // Standard der Schuleinstellung: 2 Lehrkraefte je Fachgruppe.
        $this->created(self::TEACHER_A);
        $this->created(self::TEACHER_B, '2090-09-01', '2090-12-31');

        $r = $this->assign(self::TEACHER_C, '2090-12-15');
        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('assignment_limit_reached', $r['json']['code']);

        $this->assertSame(201, $this->assign(self::TEACHER_C, '2091-01-01')['status'], 'nacheinander');

        $this->assertSame(200, $this->post('/api/school-settings/update', ['max_teachers_per_teaching_group' => 3])['status']);
        $this->assertSame(201, $this->assign(self::TEACHER_C, '2090-09-01', '2090-10-31')['status'], 'nach Erhoehung der Obergrenze');
    }

    public function testEineVerschaerfteObergrenzeLaesstBestehendesUnberuehrt(): void
    {
        $this->created(self::TEACHER_A);
        $this->created(self::TEACHER_B);
        $this->assertSame(200, $this->post('/api/school-settings/update', ['max_teachers_per_teaching_group' => 1])['status']);

        $this->assertCount(2, $this->list()['json']['teacher_assignments']);
        $this->assertSame('assignment_limit_reached', $this->assign(self::TEACHER_C)['json']['code']);
    }

    public function testAnlegenSchreibtAuditOhnePersonenbezogeneAngaben(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-10-01', '2090-12-31');

        $entries = array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'teacher_assignment.created'));
        $this->assertCount(1, $entries);
        $this->assertSame(['teacher_assignment', $id, self::ADMIN], [$entries[0]['object_type'], $entries[0]['object_id'], $entries[0]['actor_account_id']]);
        $this->assertSame(
            ['teaching_group_id' => $this->group, 'teacher_id' => self::TEACHER_A, 'starts_on' => '2090-10-01', 'ends_on' => '2090-12-31'],
            json_decode($entries[0]['context'], true),
        );
        $this->assertStringNotContainsString('example.test', $entries[0]['context']);
    }

    // --- Lesen ---

    public function testListeNachBeginnDannLehrkraftMitStatus(): void
    {
        $late = $this->created(self::TEACHER_B, '2091-01-01');
        $early = $this->created(self::TEACHER_A, '2090-09-01', '2090-12-31');

        $r = $this->list(self::VIEWER);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame([$early, $late], array_column($r['json']['teacher_assignments'], 'id'));
    }

    public function testStatusFolgtDerFestenUhr(): void
    {
        $this->created(self::TEACHER_A, '2090-09-01', '2090-11-20', self::DATED, self::TODAY);
        $this->created(self::TEACHER_B, '2090-11-21', null, self::DATED, self::TODAY);
        $this->created(self::TEACHER_C, '2090-11-22', null, self::DATED, self::TODAY);

        $statuses = array_column($this->list(self::ADMIN, self::DATED, self::TODAY)['json']['teacher_assignments'], 'status');

        $this->assertSame(['ended', 'running', 'upcoming'], $statuses);
    }

    // --- Beenden ---

    public function testEineOffeneZuordnungBeendenSetztDasEndeUndBehaeltDenBeginn(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', null, self::DATED, self::TODAY);

        $r = $this->request('POST', self::DATED . '/' . $id . '/end', ['ends_on' => '2090-12-18'], self::ADMIN, self::TODAY);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['2090-09-01', '2090-12-18'], [$r['json']['teacher_assignment']['starts_on'], $r['json']['teacher_assignment']['ends_on']]);
        $row = $this->rows()[0];
        $this->assertSame(['2090-09-01', '2090-12-19'], [$row['valid_from'], $row['valid_until']]);
        $ended = array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'teacher_assignment.ended'));
        $this->assertCount(1, $ended);
        $this->assertSame(['teaching_group_id' => $this->group, 'teacher_id' => self::TEACHER_A, 'starts_on' => '2090-09-01', 'ends_on' => '2090-12-18'], json_decode($ended[0]['context'], true));
    }

    public function testBeendenOhneAngabeBeendetHeute(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', null, self::DATED, self::TODAY);

        $r = $this->request('POST', self::DATED . '/' . $id . '/end', [], self::ADMIN, self::TODAY);

        $this->assertSame('2090-11-21', $r['json']['teacher_assignment']['ends_on']);
    }

    public function testEinInDerZukunftLiegendesEndeLaesstSichVorverlegen(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', '2091-03-01', self::DATED, self::TODAY);

        $r = $this->request('POST', self::DATED . '/' . $id . '/end', ['ends_on' => '2090-12-18'], self::ADMIN, self::TODAY);

        $this->assertSame('2090-12-18', $r['json']['teacher_assignment']['ends_on']);
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

    public function testEineBeendeteZuordnungLaesstSichNichtMehrBeenden(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', '2090-11-01', self::DATED, self::TODAY);

        $r = $this->request('POST', self::DATED . '/' . $id . '/end', ['ends_on' => '2090-10-01'], self::ADMIN, self::TODAY);

        $this->assertSame([409, 'assignment_ended'], [$r['status'], $r['json']['code']]);
    }

    public function testBeendenEinerUnbekanntenZuordnungIst404(): void
    {
        $r = $this->post(self::URI . '/00000000-0000-4000-8000-000000000000/end', ['ends_on' => '2090-10-01']);

        $this->assertSame([404, 'teacher_assignment_not_found'], [$r['status'], $r['json']['code']]);
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
        $deleted = array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === 'teacher_assignment.deleted'));
        $this->assertCount(1, $deleted);
        $this->assertSame($future, $deleted[0]['object_id']);
        $this->assertSame(['teaching_group_id' => $this->group, 'teacher_id' => self::TEACHER_A, 'starts_on' => '2090-11-22', 'ends_on' => null], json_decode($deleted[0]['context'], true));
    }

    public function testLoeschenEinerUnbekanntenZuordnungIst404(): void
    {
        $r = $this->post(self::URI . '/00000000-0000-4000-8000-000000000000/delete');

        $this->assertSame([404, 'teacher_assignment_not_found'], [$r['status'], $r['json']['code']]);
    }

    // --- Schuljahr aendern (SchoolYearGuard) ---

    private function updateYear(string $from, string $until): array
    {
        return $this->post('/api/school-years/' . $this->year . '/update', ['starts_on' => $from, 'ends_on' => $until]);
    }

    public function testEinSchuljahrDasZeitraeumeHerausfallenLiesse409OhneStilleKuerzung(): void
    {
        $this->created(self::TEACHER_A, '2090-10-01', '2091-03-31');

        foreach ([['2090-10-02', '2091-07-31'], ['2090-09-01', '2091-03-30']] as [$from, $until]) {
            $r = $this->updateYear($from, $until);
            $this->assertSame(409, $r['status'], $r['body']);
            $this->assertSame('school_year_has_teacher_assignments', $r['json']['code']);
        }
        $this->assertSame(['2090-09-01', '2091-07-31'], [$this->app->schoolYears()[0]['starts_on'], $this->app->schoolYears()[0]['ends_on']]);
        $this->assertSame('2091-04-01', $this->rows()[0]['valid_until'], 'nichts gekuerzt');
    }

    public function testEinSchuljahrAenderungOhneHerausfallendeZeitraeumeBleibtErlaubt(): void
    {
        $this->created(self::TEACHER_A, '2090-10-01', '2091-03-31');
        $this->created(self::TEACHER_B, '2090-10-02');

        $this->assertSame(200, $this->updateYear('2090-10-01', '2091-07-31')['status'], 'Beginn auf den Beginn einer Zuordnung');
        $this->assertSame(200, $this->updateYear('2090-09-01', '2091-03-31')['status'], 'ein offenes Ende folgt dem Schuljahresende');
        $this->assertSame('2091-03-31', $this->list()['json']['teacher_assignments'][1]['last_day']);
    }

    public function testEinSchuljahrDessenOffeneZuordnungNachDemNeuenEndeBeginntIst409(): void
    {
        $this->created(self::TEACHER_A, '2091-06-01');

        $this->assertSame('school_year_has_teacher_assignments', $this->updateYear('2090-09-01', '2091-05-31')['json']['code']);
    }

    // --- Archivierte und verknuepfte Fachgruppe (Ticket #65) ---

    private function archiveGroup(): void
    {
        $r = $this->post('/api/teaching-groups/' . $this->group . '/archive');
        $this->assertSame(200, $r['status'], $r['body']);
    }

    public function testEinerArchiviertenFachgruppeWirdNichtsNeuZugeordnet(): void
    {
        $this->archiveGroup();

        $r = $this->assign(self::TEACHER_A);

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('teaching_group_archived', $r['json']['code']);
        $this->assertSame([], $this->rows());
    }

    public function testArchivierenBeendetLaufendeZuordnungenNichtSieLassenSichWeiterBeendenUndLesen(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', null, self::DATED, self::TODAY);

        $this->archiveGroup();

        $row = $this->rows()[0];
        $this->assertSame(['2090-09-01', null], [$row['valid_from'], $row['valid_until']], 'unveraendert');
        $this->assertCount(1, $this->list(self::ADMIN, self::DATED, self::TODAY)['json']['teacher_assignments']);
        $r = $this->request('POST', self::DATED . '/' . $id . '/end', ['ends_on' => '2090-12-18'], self::ADMIN, self::TODAY);
        $this->assertSame(200, $r['status'], $r['body']);
    }

    public function testEineFachgruppeMitZuordnungenGiltAlsVerknuepftLoeschenWirdAufArchivierenVerwiesen(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-09-01', '2090-10-01', self::DATED, self::TODAY);

        $r = $this->post('/api/teaching-groups/' . $this->group . '/delete');

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('teaching_group_linked', $r['json']['code']);
        $this->assertStringContainsString('Lehrkraft-Zuordnung', $r['json']['error']);
        $this->assertStringContainsString('archivieren', $r['json']['error']);
        $this->assertCount(1, $this->app->teachingGroups());

        $this->archiveGroup();
        $this->assertSame('teaching_group_linked', $this->post('/api/teaching-groups/' . $this->group . '/delete')['json']['code'], 'auch archiviert');
        $this->assertSame($id, $this->rows()[0]['id']);
    }

    public function testEineFachgruppeOhneZuordnungenLaesstSichLoeschen(): void
    {
        $id = $this->created(self::TEACHER_A, '2090-10-01');
        $this->assertSame(200, $this->post(self::URI . '/' . $id . '/delete')['status']);

        $this->assertSame(200, $this->post('/api/teaching-groups/' . $this->group . '/delete')['status']);
    }

    public function testDieMigrationIstWiederholbar(): void
    {
        $this->created(self::TEACHER_A);

        $this->app->rerunMigrations('0024_teacher_assignments.php');

        $this->assertCount(1, $this->rows());
    }
}
