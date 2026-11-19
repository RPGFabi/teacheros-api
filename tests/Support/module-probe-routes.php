<?php

declare(strict_types=1);

// Test-Routen (nur bei APP_ENV=test ueber TEST_ROUTES geladen, siehe AppBootstrap::registerTestRoutes).
// Solange es keine echte Fachroute gibt, steht diese Probe-Route stellvertretend fuer eine Route des Moduls `observe`:
// gebaut wie jede kuenftige Fachroute ueber ModuleGate::route() und mit einem Recht dahinter.

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Bootstrap\ApiRouter;
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
};
