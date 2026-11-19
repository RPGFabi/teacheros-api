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
use TeacherOS\Modules\SchoolYears\SchoolYearRoutes;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleGate;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

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
