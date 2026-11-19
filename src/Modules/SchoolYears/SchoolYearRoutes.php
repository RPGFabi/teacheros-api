<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleGate;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

/**
 * Routen der Schuljahre (Tickets #54, #56): Anmeldung, Modulpruefung (`core`), dann das Recht, in dieser Reihenfolge
 * ({@see ModuleGate::route()}). Modul und Pfad sind nur fuer Tests austauschbar: die Testumgebung haengt dieselben
 * Routen unter ein Modul ohne Freigabe, um die Reihenfolge zu beweisen (tests/Support/module-probe-routes.php).
 * Nur GET/POST, weil der CORS-Standard nur diese zulaesst.
 */
final class SchoolYearRoutes
{
    public static function register(ApiRouter $router, string $moduleId = ModuleCatalog::CORE, string $path = '/api/school-years'): void
    {
        $router->get($path, [SchoolYearsController::class, 'index'], ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::SCHOOL_YEARS_VIEW)]));
        $manage = ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::SCHOOL_YEARS_MANAGE)]);
        $router->post($path, [SchoolYearsController::class, 'create'], $manage);
        // Ticket #56: Aendern und Loeschen als POST (der CORS-Standard laesst kein PUT/DELETE zu), wie bei den Rollen.
        $router->post($path . '/:id/update', [SchoolYearsController::class, 'update'], $manage);
        $router->post($path . '/:id/delete', [SchoolYearsController::class, 'delete'], $manage);
    }
}
