<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleGate;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

/**
 * Routen der Faecher (Tickets #62, #63): Anmeldung, Modulpruefung (`core`), dann das Recht, in dieser Reihenfolge
 * ({@see ModuleGate::route()}). Modul und Pfad sind nur fuer Tests austauschbar (tests/Support/module-probe-routes.php).
 * Nur GET/POST, weil der CORS-Standard nur diese zulaesst.
 */
final class SubjectRoutes
{
    public static function register(ApiRouter $router, SubjectsController $controller, string $moduleId = ModuleCatalog::CORE, string $path = '/api/subjects'): void
    {
        $view = ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::SUBJECTS_VIEW)]);
        $router->get($path, [$controller, 'index'], $view);
        $router->get($path . '/selectable', [$controller, 'selectable'], $view);
        $manage = ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::SUBJECTS_MANAGE)]);
        $router->post($path, [$controller, 'create'], $manage);
        $router->post($path . '/:id/update', [$controller, 'update'], $manage);
        $router->post($path . '/:id/archive', [$controller, 'archive'], $manage);
        $router->post($path . '/:id/restore', [$controller, 'restore'], $manage);
        $router->post($path . '/:id/delete', [$controller, 'delete'], $manage);
    }
}
