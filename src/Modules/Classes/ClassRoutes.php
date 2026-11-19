<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleGate;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

/**
 * Routen der Klassen (Ticket #55): Anmeldung, Modulpruefung (`core`), dann das Recht, in dieser Reihenfolge
 * ({@see ModuleGate::route()}). Modul und Pfad sind nur fuer Tests austauschbar (tests/Support/module-probe-routes.php).
 * Nur GET/POST, weil der CORS-Standard nur diese zulaesst.
 */
final class ClassRoutes
{
    public static function register(ApiRouter $router, string $moduleId = ModuleCatalog::CORE, string $path = '/api/classes'): void
    {
        $view = ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::CLASSES_VIEW)]);
        $router->get($path, [ClassesController::class, 'index'], $view);
        $router->get($path . '/school-years', [ClassesController::class, 'schoolYears'], $view);
        $router->post($path, [ClassesController::class, 'create'], ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::CLASSES_MANAGE)]));
    }
}
