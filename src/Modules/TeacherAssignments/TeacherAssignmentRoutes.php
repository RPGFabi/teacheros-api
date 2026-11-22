<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Shared\Assignments\AssignmentsController;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleGate;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

/**
 * Routen der Lehrkraft-Zuordnung zu Fachgruppen (Ticket #67): Anmeldung, Modulpruefung (`core`), dann das Recht, in dieser
 * Reihenfolge ({@see ModuleGate::route()}). Lesen mit `teaching_groups.view`, Aendern und die Auswahl der Lehrkraefte mit
 * `teacher_assignments.manage`. Modul und Pfad sind nur fuer Tests austauschbar (tests/Support/module-probe-routes.php).
 * Nur GET/POST (CORS): Beenden und Loeschen sind `POST .../:id/end|delete`. Der feste Pfad `teachers` steht vor `:id`.
 */
final class TeacherAssignmentRoutes
{
    public static function register(ApiRouter $router, AssignmentsController $controller, string $moduleId = ModuleCatalog::CORE, string $path = '/api/teacher-assignments'): void
    {
        $router->get($path, [$controller, 'index'], ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::TEACHING_GROUPS_VIEW)]));
        $manage = ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::TEACHER_ASSIGNMENTS_MANAGE)]);
        $router->get($path . '/teachers', [$controller, 'teachers'], $manage);
        $router->post($path, [$controller, 'create'], $manage);
        $router->post($path . '/:id/end', [$controller, 'end'], $manage);
        $router->post($path . '/:id/delete', [$controller, 'delete'], $manage);
    }
}
