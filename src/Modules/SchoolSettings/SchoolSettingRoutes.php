<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolSettings;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleGate;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

/**
 * Routen der Schuleinstellungen (Ticket #66): Anmeldung, Modulpruefung (`core`), dann das Recht `school_settings.manage`
 * fuer Lesen und Aendern. Die Schuleinstellung ist von der Modulfreigabe getrennt: `core` ist immer aktiv. Nur GET/POST,
 * weil der CORS-Standard nur diese zulaesst.
 */
final class SchoolSettingRoutes
{
    public static function register(ApiRouter $router, SchoolSettingsController $controller, string $moduleId = ModuleCatalog::CORE, string $path = '/api/school-settings'): void
    {
        $manage = ModuleGate::route($moduleId, [RequirePermission::for(PermissionCatalog::SCHOOL_SETTINGS_MANAGE)]);
        $router->get($path, [$controller, 'index'], $manage);
        $router->post($path . '/update', [$controller, 'update'], $manage);
    }
}
