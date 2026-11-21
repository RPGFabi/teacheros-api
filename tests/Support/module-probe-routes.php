<?php

declare(strict_types=1);

// Test-Routen (nur bei APP_ENV=test ueber TEST_ROUTES geladen, siehe AppBootstrap::registerTestRoutes). Die Datei bekommt
// den Router und die Erweiterungspunkte der Anwendung und meldet sich daran an, wie es ein spaeteres Modul tut.
// Solange es keine echte Fachroute gibt, steht die Probe-Route stellvertretend fuer eine Route des Moduls `observe`:
// gebaut wie jede kuenftige Fachroute ueber ModuleGate::route() und mit einem Recht dahinter.

use Hoelzle\Core\Database\DB;
use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Modules\Classes\ClassesModule;
use TeacherOS\Modules\Classes\ClassLinkGuard;
use TeacherOS\Modules\Classes\ClassLinkGuards;
use TeacherOS\Modules\Classes\ClassRoutes;
use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolSettings\SchoolLimits;
use TeacherOS\Modules\SchoolYears\SchoolYearGuard;
use TeacherOS\Modules\SchoolYears\SchoolYearGuards;
use TeacherOS\Modules\SchoolYears\SchoolYearObjection;
use TeacherOS\Modules\SchoolYears\SchoolYearSpan;
use TeacherOS\Modules\SchoolYears\SchoolYearRoutes;
use TeacherOS\Modules\SchoolYears\SchoolYearsModule;
use TeacherOS\Modules\Subjects\SubjectRoutes;
use TeacherOS\Modules\Subjects\SubjectsModule;
use TeacherOS\Modules\TeachingGroups\TeachingGroupRoutes;
use TeacherOS\Modules\TeachingGroups\TeachingGroupsModule;
use TeacherOS\Shared\Extension\ExtensionPoints;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleGate;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

/**
 * Test-Pruefung fuer den Erweiterungspunkt der Schuljahre (Ticket #56): meldet sich wie ein spaeteres Modul an und
 * erhebt Einwand gegen Aendern oder Loeschen genau der Schuljahre, die der Test in `test_school_year_blocks` eintraegt
 * (`HttpTestApp::blockSchoolYear`). Ohne Eintrag hat sie nichts einzuwenden.
 */
final class TestSchoolYearGuard implements SchoolYearGuard
{
    public function objectionToUpdate(SchoolYear $year, SchoolYearSpan $newSpan): ?SchoolYearObjection
    {
        return $this->blocked($year->id, 'update') ? new SchoolYearObjection('test_update_blocked', 'Test: Aendern gesperrt.') : null;
    }

    public function objectionToDeletion(SchoolYear $year): ?SchoolYearObjection
    {
        return $this->blocked($year->id, 'delete') ? new SchoolYearObjection('test_delete_blocked', 'Test: Loeschen gesperrt.') : null;
    }

    private function blocked(string $schoolYearId, string $action): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM `test_school_year_blocks` WHERE `school_year_id` = :id AND `action` = :action');
        $stmt->execute(['id' => $schoolYearId, 'action' => $action]);
        return $stmt->fetchColumn() !== false;
    }
}

/**
 * Test-Verknuepfung fuer den Erweiterungspunkt der Klassen (Ticket #57), wie es spaeter #18/#19 tun: verknuepft ist, was in
 * `test_class_links` steht (`HttpTestApp::linkClass`).
 */
final class TestClassLinkGuard implements ClassLinkGuard
{
    public function label(): string
    {
        return 'Testverknüpfung';
    }

    public function isLinked(string $classId): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM `test_class_links` WHERE `class_id` = :id');
        $stmt->execute(['id' => $classId]);
        return $stmt->fetchColumn() !== false;
    }
}

return static function (ApiRouter $router, ExtensionPoints $points): void {
    $router->get(
        '/api/test/observe-probe',
        static function (Request $request, Response $response): void {
            $response->json(['ok' => true]);
        },
        ModuleGate::route(ModuleCatalog::OBSERVE, [RequirePermission::for(PermissionCatalog::ACCOUNTS_VIEW)]),
    );

    // Die echten Schuljahr- und Klassen-Routen (Tickets #54, #55) unter einem Modul ohne Freigabe: beweist, dass die
    // Modulpruefung vor den Rechten laeuft. Mit den Diensten der Anwendung, also denselben Erweiterungspunkten.
    SchoolYearRoutes::register($router, (new SchoolYearsModule())->controller($points), ModuleCatalog::OBSERVE, '/api/test/observe-school-years');
    ClassRoutes::register($router, (new ClassesModule())->controller($points), ModuleCatalog::OBSERVE, '/api/test/observe-classes');
    SubjectRoutes::register($router, (new SubjectsModule())->controller($points), ModuleCatalog::OBSERVE, '/api/test/observe-subjects');
    TeachingGroupRoutes::register($router, (new TeachingGroupsModule())->controller($points), ModuleCatalog::OBSERVE, '/api/test/observe-teaching-groups');

    // Stellvertretend fuer ein spaeteres Zuordnungs-Modul (#67, #68): liest die Obergrenzen der Schuleinstellungen
    // ausschliesslich ueber den Dienst SchoolLimits, ohne deren Speicherung zu kennen.
    $router->get(
        '/api/test/school-limits',
        static function (Request $request, Response $response): void {
            $limits = new SchoolLimits();
            $response->json([
                'max_teachers_per_teaching_group' => $limits->maxTeachersPerTeachingGroup(),
                'max_class_leaders_per_class' => $limits->maxClassLeadersPerClass(),
            ]);
        },
        ModuleGate::route(ModuleCatalog::CORE, [RequirePermission::for(PermissionCatalog::ACCOUNTS_VIEW)]),
    );

    // Die Test-Pruefungen haengen hinter den echten (Tickets #56, #57).
    $points->get(SchoolYearGuards::class)->register(new TestSchoolYearGuard());
    $points->get(ClassLinkGuards::class)->register(new TestClassLinkGuard());
};
