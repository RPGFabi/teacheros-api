<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleGate;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

/**
 * Routen der Fachgruppen (Ticket #64): Anmeldung, Modulpruefung (`core`), dann das Recht, in dieser Reihenfolge
 * ({@see ModuleGate::route()}). Modul und Pfad sind nur fuer Tests austauschbar (tests/Support/module-probe-routes.php).
 * Nur GET/POST, weil der CORS-Standard nur diese zulaesst. Die festen Pfade (`school-years`, `choices`) stehen vor
 * `:id`: der Router nimmt die erste passende Route.
 */
final class TeachingGroupRoutes
{
    public static function register(ApiRouter $router, TeachingGroupsController $controller, string $moduleId = ModuleCatalog::CORE, string $path = '/api/teaching-groups'): void
    {
        $view = ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::TEACHING_GROUPS_VIEW)]);
        $router->get($path, [$controller, 'index'], $view);
        $router->get($path . '/school-years', [$controller, 'schoolYears'], $view);
        $router->get($path . '/choices', [$controller, 'choices'], $view);
        $router->get($path . '/:id', [$controller, 'show'], $view);
        $manage = ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::TEACHING_GROUPS_MANAGE)]);
        $router->post($path, [$controller, 'create'], $manage);
    }
}
