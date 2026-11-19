<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleGate;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

/**
 * Routen der Schuljahre (Ticket #54): Anmeldung, Modulpruefung (`core`), dann das Recht, in dieser Reihenfolge
 * ({@see ModuleGate::route()}). Modul und Pfad sind nur fuer Tests austauschbar: die Testumgebung haengt dieselben
 * Routen unter ein Modul ohne Freigabe, um die Reihenfolge zu beweisen (tests/Support/module-probe-routes.php).
 * Nur GET/POST, weil der CORS-Standard nur diese zulaesst.
 */
final class SchoolYearRoutes
{
    public static function register(ApiRouter $router, string $moduleId = ModuleCatalog::CORE, string $path = '/api/school-years'): void
    {
        $router->get($path, [SchoolYearsController::class, 'index'], ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::SCHOOL_YEARS_VIEW)]));
        $router->post($path, [SchoolYearsController::class, 'create'], ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::SCHOOL_YEARS_MANAGE)]));
    }
}
