<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Die gemeinsamen Endpunkt-Tests der Zuordnungen ueber HTTP gegen php-cgi + Test-DB: Lehrkraft-Zuordnung zu Fachgruppen (#67)
 * und Klassenleitung (#68) sind ein Dienst mit zwei Arten ({@see \TeacherOS\Shared\Assignments\AssignmentKind}), also gelten
 * dieselben Faelle fuer beide. Die Unterklassen sagen nur, woran die Zuordnung haengt (Pfade, Schluessel, Rechte, Codes, wie ein
 * Elternobjekt entsteht, archiviert und geloescht wird) und ergaenzen, was nur sie haben. Die Regeln selbst prueft einmal
 * `AssignmentRulesTest`. Das Schuljahr liegt 2090/91, also in der Zukunft der echten Uhr; fuer "heute"-abhaengige Faelle haengt
 * dieselbe Routen-Gruppe unter einer Test-Uhr mit festem Tag (tests/Support/module-probe-routes.php, TEST_TODAY).
 */
abstract class AssignmentEndpointsTestCase extends TestCase
{
    protected const TODAY = '2090-11-21';

    protected HttpTestApp $app;
    protected string $year;
    protected string $parent;
    protected string $admin;
    protected string $viewer;
    protected string $manager;
    protected string $nobody;
    protected string $teacherA;
    protected string $teacherB;
    protected string $teacherC;

    /** Gemeinsamer Anfang der Konto-Kennungen dieser Testklasse (UUID bis auf die letzten 12 Zeichen, mit Bindestrich am Ende). */
    abstract protected function idPrefix(): string;

    /** Pfad der Zuordnungen, z. B. `/api/class-leaders`. */
    abstract protected function uri(): string;

    /** Pfad derselben Routen unter der Test-Uhr (TEST_TODAY). */
    abstract protected function datedUri(): string;

    /** Pfad derselben Routen unter einem Modul ohne Freigabe (`observe`). */
    abstract protected function observeUri(): string;

    /** Schluessel des Elternobjekts in Query, Body, Ausgabe und Audit-Kontext, z. B. `class_id`. */
    abstract protected function parentKey(): string;

    /** Schluessel einer Zuordnung in der Antwort und Objektart im Audit, z. B. `class_leader`. */
    abstract protected function itemKey(): string;

    /** Schluessel der Liste in der Antwort, z. B. `class_leaders`. */
    abstract protected function listKey(): string;

    /** Das Recht zum Lesen, z. B. `classes.view`. */
    abstract protected function viewPermission(): string;

    abstract protected function table(): string;

    /** Schluessel der Schuleinstellung mit der Obergrenze, z. B. `max_class_leaders_per_class`. */
    abstract protected function limitSetting(): string;

    abstract protected function schoolYearCode(): string;

    abstract protected function parentNotFoundCode(): string;

    abstract protected function parentArchivedCode(): string;

    abstract protected function notFoundCode(): string;

    abstract protected function parentLinkedCode(): string;

    /** Wie die Verknuepfung in der Meldung heisst, z. B. `Klassenleitung`. */
    abstract protected function linkLabel(): string;

    /** Pfad der Elternobjekte, z. B. `/api/classes`. */
    abstract protected function parentUri(): string;

    /** @return list<array<string,mixed>> die Zeilen der Elternobjekte in der Datenbank */
    abstract protected function parentRows(): array;

    abstract protected function migrationFile(): string;

    /** Legt im Schuljahr ein Elternobjekt an (mit allem, was es braucht) und liefert seine Kennung. */
    abstract protected function createParent(string $yearId, string $name): string;

    /** Raeumt zusaetzlich zurueck, was die Unterklasse anlegt (z. B. Faecher). */
    protected function resetMore(): void
    {
    }

    /** @return list<\Closure(string):array{status:int,body:string,json:?array}> weitere Anfragen, die nur mit dem Verwaltungsrecht gehen (z. B. die Liste der waehlbaren Lehrkraefte) */
    protected function extraManageRequests(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->resetSchoolYears();
        $this->app->resetSchoolSettings();
        $this->resetMore();
        [$this->admin, $this->viewer, $this->manager, $this->nobody] = array_map(fn (int $n): string => $this->idPrefix() . '00000000000' . $n, [1, 2, 3, 4]);
        [$this->teacherA, $this->teacherB, $this->teacherC] = array_map(fn (string $x): string => $this->idPrefix() . '0000000000' . $x, ['a1', 'b2', 'c3']);
        foreach ([$this->admin => null, $this->viewer => $this->viewPermission(), $this->manager => 'teacher_assignments.manage', $this->nobody => null] as $sub => $permission) {
            $this->app->createAccount($sub, 'active');
            if ($permission !== null) {
                $this->app->grantPermission($sub, $permission);
            }
        }
        $this->app->assignRole($this->admin, 'schuladmin');
        foreach ([$this->teacherA, $this->teacherB, $this->teacherC] as $i => $teacher) {
            $this->app->createAccount($teacher, 'active', "lehrkraft{$i}@example.test", "lehrkraft{$i}");
            $this->app->assignRole($teacher, 'lehrkraft');
        }
        $this->year = $this->createYear('2090-09-01', '2091-07-31');
        $this->parent = $this->createParent($this->year, '7a');
    }

    protected function tearDown(): void
    {
        // Die Modulpruefungs-Tests geben das Modul `observe` frei; andere Testklassen erwarten es ungenehmigt.
        $this->app->resetModules();
    }

    // --- Hilfen ---

    /** @return array{status:int, body:string, json:?array} */
    protected function request(string $method, string $uri, ?array $body = null, ?string $sub = null, ?string $today = null): array
    {
        $env = ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub ?? $this->admin)] + ($today === null ? [] : ['TEST_TODAY' => $today]);
        return $this->app->request($method, $uri, $env, $body);
    }

    /** @return array{status:int, body:string, json:?array} */
    protected function post(string $uri, array $body = [], ?string $sub = null): array
    {
        return $this->request('POST', $uri, $body, $sub);
    }

    protected function createYear(string $starts, string $ends): string
    {
        $r = $this->post('/api/school-years', ['starts_on' => $starts, 'ends_on' => $ends]);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json']['school_year']['id'];
    }

    /** @return array{status:int, body:string, json:?array} */
    protected function assign(string $teacher, ?string $from = null, ?string $until = null, ?string $sub = null, ?string $uri = null, ?string $today = null, ?string $parent = null): array
    {
        $body = [$this->parentKey() => $parent ?? $this->parent, 'teacher_id' => $teacher];
        if ($from !== null) {
            $body['starts_on'] = $from;
        }
        if ($until !== null) {
            $body['ends_on'] = $until;
        }
        return $this->request('POST', $uri ?? $this->uri(), $body, $sub, $today);
    }

    protected function created(string $teacher, ?string $from = null, ?string $until = null, ?string $uri = null, ?string $today = null, ?string $parent = null): string
    {
        $r = $this->assign($teacher, $from, $until, null, $uri, $today, $parent);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json'][$this->itemKey()]['id'];
    }

    /** @return array{status:int, body:string, json:?array} */
    protected function list(?string $sub = null, ?string $uri = null, ?string $today = null, ?string $parent = null): array
    {
        return $this->request('GET', ($uri ?? $this->uri()) . '?' . $this->parentKey() . '=' . ($parent ?? $this->parent), null, $sub, $today);
    }

    /** @return list<array<string,mixed>> */
    protected function listed(?string $sub = null, ?string $uri = null, ?string $today = null): array
    {
        return $this->list($sub, $uri, $today)['json'][$this->listKey()];
    }

    /** @return list<array<string,mixed>> */
    protected function rows(): array
    {
        return $this->app->pdo()->query('SELECT * FROM `' . $this->table() . '` ORDER BY `created_at`, `id`')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    protected function audit(string $action): array
    {
        return array_values(array_filter($this->app->auditLog(), static fn (array $e): bool => $e['action'] === $action));
    }

    // --- Rechte und Modul ---

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('GET', $this->uri() . '?' . $this->parentKey() . '=' . $this->parent)['status']);
        $this->assertSame(401, $this->app->request('POST', $this->uri(), [], [])['status']);
        $this->assertSame(401, $this->app->request('POST', $this->uri() . '/x/end', [], [])['status']);
        $this->assertSame(401, $this->app->request('POST', $this->uri() . '/x/delete', [], [])['status']);
    }

    public function testLesenBrauchtDasRechtDesElternobjektsVerwaltenBrauchtTeacherAssignmentsManage(): void
    {
        $id = $this->created($this->teacherA, '2090-10-01');

        foreach ([$this->nobody, $this->manager] as $sub) {
            $r = $this->list($sub);
            $this->assertSame(403, $r['status'], $sub);
            $this->assertSame('forbidden', $r['json']['code'], $sub);
        }
        $this->assertSame(200, $this->list($this->viewer)['status']);

        $writes = [
            fn (string $sub): array => $this->assign($this->teacherB, '2090-10-01', null, $sub),
            fn (string $sub): array => $this->request('POST', $this->uri() . '/' . $id . '/end', ['ends_on' => '2090-12-01'], $sub),
            fn (string $sub): array => $this->request('POST', $this->uri() . '/' . $id . '/delete', [], $sub),
            ...$this->extraManageRequests(),
        ];
        foreach ($writes as $i => $write) {
            foreach ([$this->nobody, $this->viewer] as $sub) {
                $r = $write($sub);
                $this->assertSame(403, $r['status'], "$i $sub");
                $this->assertSame('forbidden', $r['json']['code'], "$i $sub");
            }
        }
        $this->assertCount(1, $this->rows(), 'ohne Recht aendert sich nichts');
        $this->assertSame(201, $this->assign($this->teacherB, '2090-10-01', null, $this->manager)['status'], 'teacher_assignments.manage genuegt zum Zuordnen');
    }

    public function testModulpruefungLaeuftVorDerRechtepruefung(): void
    {
        $this->app->resetModules();
        $probe = $this->observeUri();

        foreach ([$this->list($this->admin, $probe), $this->list($this->nobody, $probe), $this->assign($this->teacherA, null, null, $this->nobody, $probe), $this->request('POST', $probe . '/x/end', [], $this->nobody), $this->request('POST', $probe . '/x/delete', [], $this->nobody)] as $i => $r) {
            $this->assertSame(403, $r['status'], (string) $i);
            $this->assertSame('module_disabled', $r['json']['code'], (string) $i);
        }
        $this->assertSame([], $this->rows());

        $this->app->insertEntitlement('observe', true, gmdate('Y-m-d H:i:s.u', time() - 3600));
        $this->assertSame('forbidden', $this->list($this->nobody, $probe)['json']['code']);
        $this->assertSame(200, $this->list($this->admin, $probe)['status']);
    }

    // --- Anlegen ---

    public function testAnlegenOhneGueltigkeitszeitraumGiltBisSchuljahresendeAbSchuljahresanfang(): void
    {
        $r = $this->assign($this->teacherA);

        $this->assertSame(201, $r['status'], $r['body']);
        $a = $r['json'][$this->itemKey()];
        $this->assertSame($this->parent, $a[$this->parentKey()]);
        $this->assertSame('2090-09-01', $a['starts_on']);
        $this->assertNull($a['ends_on']);
        $this->assertSame('2091-07-31', $a['last_day']);
        $this->assertSame('upcoming', $a['status']);
        $row = $this->rows()[0];
        $this->assertSame(['2090-09-01', null], [$row['valid_from'], $row['valid_until']]);
    }

    public function testDieLehrkraftDerAusgabeNenntNurKennungUndAnmeldenameKeineEmailKeinenKontostatus(): void
    {
        $created = $this->assign($this->teacherA);
        $this->assertSame(['id' => $this->teacherA, 'login_name' => 'lehrkraft0'], $created['json'][$this->itemKey()]['teacher']);
        $id = $created['json'][$this->itemKey()]['id'];

        $ended = $this->request('POST', $this->uri() . '/' . $id . '/end', ['ends_on' => '2090-12-18']);
        $this->assertSame(['id' => $this->teacherA, 'login_name' => 'lehrkraft0'], $ended['json'][$this->itemKey()]['teacher']);

        foreach ([$this->admin, $this->viewer] as $sub) {
            $list = $this->list($sub);
            $this->assertSame(200, $list['status'], $list['body']);
            $this->assertSame(['id' => $this->teacherA, 'login_name' => 'lehrkraft0'], $list['json'][$this->listKey()][0]['teacher'], $sub);
            $this->assertStringNotContainsString('example.test', $list['body'], 'keine E-Mail fuer reine Leser');
            $this->assertStringNotContainsString('"email"', $list['body']);
            $this->assertStringNotContainsString('"status":"active"', $list['body'], 'kein Kontostatus (der Status der Zuordnung ist ein anderer)');
        }
    }

    public function testDasEndeIstEinschliesslichUndGespeichertWirdDieExklusiveObergrenze(): void
    {
        $a = $this->assign($this->teacherA, '2090-10-01', '2090-12-31')['json'][$this->itemKey()];

        $this->assertSame(['2090-10-01', '2090-12-31', '2090-12-31'], [$a['starts_on'], $a['ends_on'], $a['last_day']]);
        $row = $this->rows()[0];
        $this->assertSame(['2090-10-01', '2091-01-01'], [$row['valid_from'], $row['valid_until']]);
    }

    public function testGueltigkeitszeitraumAusserhalbDesSchuljahresIst422(): void
    {
        foreach ([['2090-08-31', null], ['2091-08-01', null], [null, '2091-08-01']] as [$from, $until]) {
            $r = $this->assign($this->teacherA, $from, $until);
            $this->assertSame(422, $r['status'], $r['body']);
            $this->assertSame('validity_outside_school_year', $r['json']['code']);
        }
        $this->assertSame('invalid_validity', $this->assign($this->teacherA, '2090-12-01', '2090-11-30')['json']['code']);
        $this->assertSame([], $this->rows());
    }

    public function testUngueltigeEingabeIst400(): void
    {
        $key = $this->parentKey();
        foreach ([[$key => $this->parent, 'starts_on' => '2090-10-01'], ['teacher_id' => $this->teacherA], [$key => $this->parent, 'teacher_id' => 5],
            [$key => $this->parent, 'teacher_id' => $this->teacherA, 'starts_on' => '01.10.2090'], [$key => $this->parent, 'teacher_id' => $this->teacherA, 'ends_on' => '2090-02-30']] as $i => $body) {
            $r = $this->post($this->uri(), $body);
            $this->assertSame(400, $r['status'], "$i " . $r['body']);
            $this->assertSame('invalid_request', $r['json']['code'], (string) $i);
        }
        $this->assertSame([], $this->rows());
    }

    public function testUnbekanntesElternobjektIst404(): void
    {
        $unknown = '00000000-0000-4000-8000-000000000000';

        $r = $this->assign($this->teacherA, parent: $unknown);

        $this->assertSame([404, $this->parentNotFoundCode()], [$r['status'], $r['json']['code']]);
        $this->assertSame($this->parentNotFoundCode(), $this->list(parent: $unknown)['json']['code']);
        $this->assertSame(400, $this->request('GET', $this->uri())['status'], 'ohne Elternobjekt');
    }

    public function testNurLehrkraefteOhneDeaktivierteSindWaehlbar(): void
    {
        $inactive = $this->idPrefix() . '0000000000d4';
        $secretary = $this->idPrefix() . '0000000000e5';
        $invited = $this->idPrefix() . '0000000000f6';
        $this->app->createAccount($inactive, 'inactive', 'gesperrt@example.test', 'gesperrt');
        $this->app->assignRole($inactive, 'lehrkraft');
        $this->app->createAccount($secretary, 'active', 'sekretariat@example.test', 'sekretariat');
        $this->app->assignRole($secretary, 'sekretariat');
        $this->app->createAccount($invited, 'invited', 'neu@example.test', 'neu');
        $this->app->assignRole($invited, 'lehrkraft');

        foreach ([$inactive, $secretary, $this->admin, '00000000-0000-4000-8000-000000000000'] as $notTeacher) {
            $r = $this->assign($notTeacher);
            $this->assertSame([409, 'teacher_not_selectable'], [$r['status'], $r['json']['code']], $notTeacher . $r['body']);
        }
        $this->assertSame(201, $this->assign($invited)['status'], 'eingeladen ist zuordenbar');
        $this->assertCount(1, $this->rows());
    }

    public function testRolleUndStatusWerdenNurBeimAnlegenGeprueftDieHistorieBleibt(): void
    {
        $id = $this->created($this->teacherA);

        $this->app->setAccountStatus($this->teacherA, 'inactive');
        $this->app->pdo()->exec("DELETE FROM `permission_user_roles` WHERE `user_uuid` = '" . $this->teacherA . "'");

        $list = $this->listed();
        $this->assertSame($id, $list[0]['id']);
        $this->assertSame(['id' => $this->teacherA, 'login_name' => 'lehrkraft0'], $list[0]['teacher'], 'bestehende Zuordnung bleibt sichtbar, ohne Kontostatus');
        $this->assertSame(200, $this->post($this->uri() . '/' . $id . '/end', ['ends_on' => '2091-06-01'])['status'], 'Beenden geht weiter');
    }

    public function testAnlegenSchreibtAuditOhnePersonenbezogeneAngaben(): void
    {
        $id = $this->created($this->teacherA, '2090-10-01', '2090-12-31');

        $entries = $this->audit($this->itemKey() . '.created');
        $this->assertCount(1, $entries);
        $this->assertSame([$this->itemKey(), $id, $this->admin], [$entries[0]['object_type'], $entries[0]['object_id'], $entries[0]['actor_account_id']]);
        $this->assertSame(
            [$this->parentKey() => $this->parent, 'teacher_id' => $this->teacherA, 'starts_on' => '2090-10-01', 'ends_on' => '2090-12-31'],
            json_decode($entries[0]['context'], true),
        );
        $this->assertStringNotContainsString('example.test', $entries[0]['context']);
    }

    // --- Lesen ---

    public function testListeNachBeginnDannKennungMitStatusNurDesElternobjekts(): void
    {
        $other = $this->createParent($this->year, '7b');
        $this->assign($this->teacherC, parent: $other);
        $late = $this->created($this->teacherB, '2091-01-01');
        $early = $this->created($this->teacherA, '2090-09-01', '2090-12-31');

        $r = $this->list($this->viewer);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame([$early, $late], array_column($r['json'][$this->listKey()], 'id'));
    }

    public function testStatusFolgtDerFestenUhr(): void
    {
        $this->created($this->teacherA, '2090-09-01', '2090-11-20', $this->datedUri(), self::TODAY);
        $this->created($this->teacherB, '2090-11-21', null, $this->datedUri(), self::TODAY);
        $this->created($this->teacherC, '2090-11-22', null, $this->datedUri(), self::TODAY);

        $statuses = array_column($this->listed($this->admin, $this->datedUri(), self::TODAY), 'status');

        $this->assertSame(['ended', 'running', 'upcoming'], $statuses);
    }

    // --- Ueberschneidung und Obergrenze ---

    public function testDieselbeLehrkraftNichtMitUeberschneidendemGueltigkeitszeitraumNacheinanderSchon(): void
    {
        $this->created($this->teacherA, '2090-09-01', '2090-12-31');

        $r = $this->assign($this->teacherA, '2090-12-31', '2091-02-01');
        $this->assertSame([409, 'assignment_overlap'], [$r['status'], $r['json']['code']], $r['body']);

        $this->assertSame(201, $this->assign($this->teacherA, '2091-01-01', '2091-02-01')['status']);
        $this->assertCount(2, $this->rows());
    }

    public function testDerselbeGueltigkeitszeitraumAnEinemAnderenElternobjektIstKeineUeberschneidung(): void
    {
        $other = $this->createParent($this->year, '7b');
        $this->created($this->teacherA);

        $this->assertSame(201, $this->assign($this->teacherA, parent: $other)['status']);
    }

    public function testDieObergrenzeAusDerSchuleinstellungGiltAnJedemTag(): void
    {
        // Standard der Schuleinstellung: 2 je Elternobjekt.
        $this->created($this->teacherA);
        $this->created($this->teacherB, '2090-09-01', '2090-12-31');

        $r = $this->assign($this->teacherC, '2090-12-15');
        $this->assertSame([409, 'assignment_limit_reached'], [$r['status'], $r['json']['code']], $r['body']);

        $this->assertSame(201, $this->assign($this->teacherC, '2091-01-01')['status'], 'nacheinander');

        $this->assertSame(200, $this->post('/api/school-settings/update', [$this->limitSetting() => 3])['status']);
        $this->assertSame(201, $this->assign($this->teacherC, '2090-09-01', '2090-10-31')['status'], 'nach Erhoehung der Obergrenze');
    }

    public function testEineVerschaerfteObergrenzeLaesstBestehendesUnberuehrt(): void
    {
        $this->created($this->teacherA);
        $this->created($this->teacherB);
        $this->assertSame(200, $this->post('/api/school-settings/update', [$this->limitSetting() => 1])['status']);

        $this->assertCount(2, $this->listed());
        $this->assertSame('assignment_limit_reached', $this->assign($this->teacherC)['json']['code']);
    }

    // --- Beenden ---

    public function testEineOffeneZuordnungBeendenSetztDasEndeUndBehaeltDenBeginn(): void
    {
        $id = $this->created($this->teacherA, '2090-09-01', null, $this->datedUri(), self::TODAY);

        $r = $this->request('POST', $this->datedUri() . '/' . $id . '/end', ['ends_on' => '2090-12-18'], null, self::TODAY);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['2090-09-01', '2090-12-18'], [$r['json'][$this->itemKey()]['starts_on'], $r['json'][$this->itemKey()]['ends_on']]);
        $row = $this->rows()[0];
        $this->assertSame(['2090-09-01', '2090-12-19'], [$row['valid_from'], $row['valid_until']]);
        $ended = $this->audit($this->itemKey() . '.ended');
        $this->assertCount(1, $ended);
        $this->assertSame([$this->itemKey(), $id], [$ended[0]['object_type'], $ended[0]['object_id']]);
        $this->assertSame([$this->parentKey() => $this->parent, 'teacher_id' => $this->teacherA, 'starts_on' => '2090-09-01', 'ends_on' => '2090-12-18'], json_decode($ended[0]['context'], true));
    }

    public function testBeendenOhneAngabeBeendetHeute(): void
    {
        $id = $this->created($this->teacherA, '2090-09-01', null, $this->datedUri(), self::TODAY);

        $r = $this->request('POST', $this->datedUri() . '/' . $id . '/end', [], null, self::TODAY);

        $this->assertSame('2090-11-21', $r['json'][$this->itemKey()]['ends_on']);
    }

    public function testEinInDerZukunftLiegendesEndeLaesstSichVorverlegen(): void
    {
        $id = $this->created($this->teacherA, '2090-09-01', '2091-03-01', $this->datedUri(), self::TODAY);

        $r = $this->request('POST', $this->datedUri() . '/' . $id . '/end', ['ends_on' => '2090-12-18'], null, self::TODAY);

        $this->assertSame('2090-12-18', $r['json'][$this->itemKey()]['ends_on']);
    }

    public function testRueckwirkendBeendenUndNachtraeglichVerlaengernSindAbgelehnt(): void
    {
        $id = $this->created($this->teacherA, '2090-09-01', '2091-03-01', $this->datedUri(), self::TODAY);

        $past = $this->request('POST', $this->datedUri() . '/' . $id . '/end', ['ends_on' => '2090-11-20'], null, self::TODAY);
        $this->assertSame([409, 'assignment_applied'], [$past['status'], $past['json']['code']]);
        $later = $this->request('POST', $this->datedUri() . '/' . $id . '/end', ['ends_on' => '2091-05-01'], null, self::TODAY);
        $this->assertSame([422, 'invalid_validity'], [$later['status'], $later['json']['code']]);
        $this->assertSame('2091-03-02', $this->rows()[0]['valid_until']);
    }

    public function testEineBeendeteZuordnungLaesstSichNichtMehrBeenden(): void
    {
        $id = $this->created($this->teacherA, '2090-09-01', '2090-11-01', $this->datedUri(), self::TODAY);

        $r = $this->request('POST', $this->datedUri() . '/' . $id . '/end', ['ends_on' => '2090-10-01'], null, self::TODAY);

        $this->assertSame([409, 'assignment_ended'], [$r['status'], $r['json']['code']]);
    }

    public function testBeendenEinerUnbekanntenZuordnungIst404(): void
    {
        $r = $this->post($this->uri() . '/00000000-0000-4000-8000-000000000000/end', ['ends_on' => '2090-10-01']);

        $this->assertSame([404, $this->notFoundCode()], [$r['status'], $r['json']['code']]);
    }

    // --- Loeschen ---

    public function testLoeschenNurBeiBeginnInDerZukunft(): void
    {
        $future = $this->created($this->teacherA, '2090-11-22', null, $this->datedUri(), self::TODAY);
        $today = $this->created($this->teacherB, '2090-11-21', null, $this->datedUri(), self::TODAY);
        $past = $this->created($this->teacherC, '2090-09-01', '2090-10-01', $this->datedUri(), self::TODAY);

        foreach ([$today, $past] as $applied) {
            $r = $this->request('POST', $this->datedUri() . '/' . $applied . '/delete', [], null, self::TODAY);
            $this->assertSame([409, 'assignment_applied'], [$r['status'], $r['json']['code']], $applied);
        }
        $this->assertCount(3, $this->rows());

        $r = $this->request('POST', $this->datedUri() . '/' . $future . '/delete', [], null, self::TODAY);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['deleted' => true, 'id' => $future], $r['json']);
        $this->assertCount(2, $this->rows());
        $deleted = $this->audit($this->itemKey() . '.deleted');
        $this->assertCount(1, $deleted);
        $this->assertSame([$this->itemKey(), $future], [$deleted[0]['object_type'], $deleted[0]['object_id']]);
        $this->assertSame([$this->parentKey() => $this->parent, 'teacher_id' => $this->teacherA, 'starts_on' => '2090-11-22', 'ends_on' => null], json_decode($deleted[0]['context'], true));
    }

    public function testLoeschenEinerUnbekanntenZuordnungIst404(): void
    {
        $r = $this->post($this->uri() . '/00000000-0000-4000-8000-000000000000/delete');

        $this->assertSame([404, $this->notFoundCode()], [$r['status'], $r['json']['code']]);
    }

    // --- Schuljahr aendern (SchoolYearGuard) ---

    /** @return array{status:int, body:string, json:?array} */
    protected function updateYear(string $from, string $until, ?string $yearId = null, ?string $today = null): array
    {
        return $this->request('POST', '/api/school-years/' . ($yearId ?? $this->year) . '/update', ['starts_on' => $from, 'ends_on' => $until], null, $today);
    }

    public function testEinSchuljahrDasZuordnungenHerausfallenLiesse409OhneStilleKuerzung(): void
    {
        $this->created($this->teacherA, '2090-10-01', '2091-03-31');

        foreach ([['2090-10-02', '2091-07-31'], ['2090-09-01', '2091-03-30']] as [$from, $until]) {
            $r = $this->updateYear($from, $until);
            $this->assertSame([409, $this->schoolYearCode()], [$r['status'], $r['json']['code']], $r['body']);
        }
        $this->assertSame(['2090-09-01', '2091-07-31'], [$this->app->schoolYears()[0]['starts_on'], $this->app->schoolYears()[0]['ends_on']]);
        $this->assertSame('2091-04-01', $this->rows()[0]['valid_until'], 'nichts gekuerzt');
    }

    public function testEineSchuljahrAenderungOhneHerausfallendeZuordnungenBleibtErlaubt(): void
    {
        $this->created($this->teacherA, '2090-10-01', '2091-03-31');
        $this->created($this->teacherB, '2090-10-02');

        $this->assertSame(200, $this->updateYear('2090-10-01', '2091-07-31')['status'], 'Beginn auf den Beginn einer Zuordnung');
        $this->assertSame(200, $this->updateYear('2090-09-01', '2091-03-31')['status'], 'ein offenes Ende folgt dem Schuljahresende');
        $this->assertSame('2091-03-31', $this->listed()[1]['last_day']);
    }

    public function testEinSchuljahrDessenOffeneZuordnungNachDemNeuenEndeBeginntIst409(): void
    {
        $this->created($this->teacherA, '2091-06-01');

        $this->assertSame($this->schoolYearCode(), $this->updateYear('2090-09-01', '2091-05-31')['json']['code']);
    }

    // Mit fester Uhr: heute ist der 2091-06-15 (die Routen melden zusaetzlich den Einwand mit diesem Tag an).

    public function testEinSchuljahresendeVorHeuteWirdAbgelehntWennEineOffeneZuordnungSchonBegonnenHat(): void
    {
        $this->created($this->teacherA, '2090-10-01');

        $r = $this->updateYear('2090-09-01', '2091-03-31', today: '2091-06-15');

        $this->assertSame([409, $this->schoolYearCode()], [$r['status'], $r['json']['code']], $r['body']);
        $this->assertStringContainsString('gegoltene', $r['json']['error']);
        $this->assertSame('2091-07-31', $this->app->schoolYears()[0]['ends_on'], 'Schuljahr unveraendert');
        $this->assertNull($this->rows()[0]['valid_until'], 'nichts still gekuerzt');
    }

    public function testEinSchuljahresendeAbHeuteLaesstDieOffeneZuordnungDemSchuljahresendeFolgen(): void
    {
        $this->created($this->teacherA, '2090-10-01');

        $this->assertSame(200, $this->updateYear('2090-09-01', '2091-07-15', today: '2091-06-15')['status'], 'Ende in der Zukunft');
        $this->assertSame(200, $this->updateYear('2090-09-01', '2091-06-15', today: '2091-06-15')['status'], 'der heutige Tag bleibt im Schuljahr');
        $this->assertSame('2091-06-15', $this->listed()[0]['last_day']);
    }

    public function testEinFruehesSchuljahresendeKuerztKeinFestesEnde(): void
    {
        $this->created($this->teacherA, '2090-10-01', '2090-11-30');
        $this->created($this->teacherB, '2090-10-01', '2090-12-15');

        $this->assertSame(200, $this->updateYear('2090-09-01', '2091-03-31', today: '2091-06-15')['status'], 'feste Enden liegen vor dem neuen Ende');
    }

    public function testEinVerlaengertesSchuljahrNimmtNiemandemTageAuchNichtVorHeute(): void
    {
        $this->created($this->teacherA, '2090-10-01');

        $this->assertSame(200, $this->updateYear('2090-09-01', '2091-08-31', today: '2092-01-15')['status']);
    }

    public function testMitDerEchtenUhrLaesstSichEinVergangenesSchuljahrMitLaufenderZuordnungNichtKuerzen(): void
    {
        $past = $this->createYear('2020-09-01', '2021-07-31');
        $parent = $this->createParent($past, '8a');
        $this->created($this->teacherA, '2020-10-01', null, null, null, $parent);

        $r = $this->updateYear('2020-09-01', '2021-06-30', $past);

        $this->assertSame([409, $this->schoolYearCode()], [$r['status'], $r['json']['code']], $r['body']);
        $this->assertSame(200, $this->updateYear('2020-09-01', '2021-08-31', $past)['status'], 'Verlaengern ist erlaubt');
    }

    // --- Archiviertes und verknuepftes Elternobjekt ---

    protected function archiveParent(): void
    {
        $r = $this->post($this->parentUri() . '/' . $this->parent . '/archive');
        $this->assertSame(200, $r['status'], $r['body']);
    }

    public function testEinemArchiviertenElternobjektWirdNichtsNeuZugeordnet(): void
    {
        $this->archiveParent();

        $r = $this->assign($this->teacherA);

        $this->assertSame([409, $this->parentArchivedCode()], [$r['status'], $r['json']['code']], $r['body']);
        $this->assertSame([], $this->rows());
    }

    public function testArchivierenBeendetLaufendeZuordnungenNichtSieLassenSichWeiterBeendenUndLesen(): void
    {
        $id = $this->created($this->teacherA, '2090-09-01', null, $this->datedUri(), self::TODAY);

        $this->archiveParent();

        $row = $this->rows()[0];
        $this->assertSame(['2090-09-01', null], [$row['valid_from'], $row['valid_until']], 'unveraendert');
        $this->assertCount(1, $this->listed($this->admin, $this->datedUri(), self::TODAY));
        $r = $this->request('POST', $this->datedUri() . '/' . $id . '/end', ['ends_on' => '2090-12-18'], null, self::TODAY);
        $this->assertSame(200, $r['status'], $r['body']);
    }

    public function testNachWiederherstellenIstDasElternobjektWiederWaehlbar(): void
    {
        $this->archiveParent();
        $this->assertSame(200, $this->post($this->parentUri() . '/' . $this->parent . '/restore')['status']);

        $this->assertSame(201, $this->assign($this->teacherA)['status']);
    }

    public function testEinElternobjektMitZuordnungGiltAlsVerknuepftLoeschenWirdAufArchivierenVerwiesen(): void
    {
        $id = $this->created($this->teacherA, '2090-09-01', '2090-10-01', $this->datedUri(), self::TODAY);
        $before = count($this->parentRows());

        $r = $this->post($this->parentUri() . '/' . $this->parent . '/delete');

        $this->assertSame([409, $this->parentLinkedCode()], [$r['status'], $r['json']['code']], $r['body']);
        $this->assertStringContainsString($this->linkLabel(), $r['json']['error']);
        $this->assertStringContainsString('archivieren', $r['json']['error']);
        $this->assertCount($before, $this->parentRows());

        $this->archiveParent();
        $this->assertSame($this->parentLinkedCode(), $this->post($this->parentUri() . '/' . $this->parent . '/delete')['json']['code'], 'auch archiviert');
        $this->assertSame($id, $this->rows()[0]['id']);
    }

    public function testEinElternobjektOhneZuordnungLaesstSichLoeschen(): void
    {
        $id = $this->created($this->teacherA, '2090-10-01');
        $this->assertSame(200, $this->post($this->uri() . '/' . $id . '/delete')['status']);

        $this->assertSame(200, $this->post($this->parentUri() . '/' . $this->parent . '/delete')['status']);
    }

    public function testDieMigrationIstWiederholbar(): void
    {
        $this->created($this->teacherA);

        $this->app->rerunMigrations($this->migrationFile());

        $this->assertCount(1, $this->rows());
    }
}
