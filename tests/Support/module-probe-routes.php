<?php

declare(strict_types=1);

// Test-Routen (nur bei APP_ENV=test ueber TEST_ROUTES geladen, siehe AppBootstrap::registerTestRoutes).
// Solange es keine echte Fachroute gibt, steht diese Probe-Route stellvertretend fuer eine Route des Moduls `observe`:
// gebaut wie jede kuenftige Fachroute ueber ModuleGate::route() und mit einem Recht dahinter.

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Bootstrap\ApiRouter;
use Hoelzle\Core\Database\DB;
use TeacherOS\Modules\Classes\ClassLinkCheck;
use TeacherOS\Modules\Classes\ClassLinkRegistry;
use TeacherOS\Modules\Classes\ClassRoutes;
use TeacherOS\Modules\SchoolYears\SchoolYearGuard;
use TeacherOS\Modules\SchoolYears\SchoolYearGuards;
use TeacherOS\Modules\SchoolYears\SchoolYearObjection;
use TeacherOS\Modules\SchoolYears\SchoolYearPeriod;
use TeacherOS\Modules\SchoolYears\SchoolYearRoutes;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleGate;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

/**
 * Test-Pruefung fuer den Erweiterungspunkt der Schuljahre (Ticket #56): meldet sich wie ein spaeteres Modul an und
 * erhebt Einwand gegen Aendern und Loeschen jedes Schuljahres, das im Jahr 2095 beginnt.
 */
final class TestSchoolYearGuard implements SchoolYearGuard
{
    public function objectionToUpdate(array $year, SchoolYearPeriod $newPeriod): ?SchoolYearObjection
    {
        return str_starts_with($year['starts_on'], '2095-') ? new SchoolYearObjection('test_update_blocked', 'Test: Aendern gesperrt.') : null;
    }

    public function objectionToDeletion(array $year): ?SchoolYearObjection
    {
        return str_starts_with($year['starts_on'], '2095-') ? new SchoolYearObjection('test_delete_blocked', 'Test: Loeschen gesperrt.') : null;
    }
}

return static function (ApiRouter $router): void {
    $router->get(
        '/api/test/observe-probe',
        static function (Request $request, Response $response): void {
            $response->json(['ok' => true]);
        },
        ModuleGate::route(ModuleCatalog::OBSERVE, [RequirePermission::for(PermissionCatalog::ACCOUNTS_VIEW)]),
    );

    // Die echten Schuljahr-Routen (Ticket #54) unter einem Modul ohne Freigabe: beweist, dass die Modulpruefung vor den Rechten laeuft.
    SchoolYearRoutes::register($router, ModuleCatalog::OBSERVE, '/api/test/observe-school-years');
    // Ebenso die Klassen-Routen (Ticket #55).
    ClassRoutes::register($router, ModuleCatalog::OBSERVE, '/api/test/observe-classes');
    // Die Test-Pruefung haengt hinter die echten Pruefungen (Ticket #56).
    SchoolYearGuards::register(new TestSchoolYearGuard());

    // Test-Verknuepfung (Ticket #57): meldet sich an der Verknuepfungspruefung der Klassen an, wie es spaeter #18/#19 tun;
    // verknuepft ist, was in `test_class_links` steht (HttpTestApp::linkClass).
    ClassLinkRegistry::shared()->register(new class implements ClassLinkCheck {
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
    });
};
