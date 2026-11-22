<?php

declare(strict_types=1);

namespace TeacherOS\Modules\ClassLeaders;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Shared\Assignments\AssignmentsController;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleGate;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

/**
 * Routen der Klassenleitung (Ticket #68): Anmeldung, Modulpruefung (`core`), dann das Recht, in dieser Reihenfolge
 * ({@see ModuleGate::route()}). Lesen mit `classes.view`, Aendern mit `teacher_assignments.manage` (kein eigenes Recht). Die
 * waehlbaren Lehrkraefte liefert `GET /api/teacher-assignments/teachers`. Modul und Pfad sind nur fuer Tests austauschbar
 * (tests/Support/module-probe-routes.php). Nur GET/POST (CORS): Beenden und Loeschen sind `POST .../:id/end|delete`.
 */
final class ClassLeaderRoutes
{
    public static function register(ApiRouter $router, AssignmentsController $controller, string $moduleId = ModuleCatalog::CORE, string $path = '/api/class-leaders'): void
    {
        $router->get($path, [$controller, 'index'], ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::CLASSES_VIEW)]));
        $manage = ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::TEACHER_ASSIGNMENTS_MANAGE)]);
        $router->post($path, [$controller, 'create'], $manage);
        $router->post($path . '/:id/end', [$controller, 'end'], $manage);
        $router->post($path . '/:id/delete', [$controller, 'delete'], $manage);
    }
}
