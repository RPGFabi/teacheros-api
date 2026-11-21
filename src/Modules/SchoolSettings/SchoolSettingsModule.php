<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolSettings;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Bootstrap\ModuleRegistration;
use TeacherOS\Shared\Extension\ExtensionPoints;

/** Anmeldung der Schuleinstellungen (Ticket #66): die Routen. Andere Module lesen die Werte ueber {@see SchoolLimits}. */
final class SchoolSettingsModule implements ModuleRegistration
{
    public function register(ApiRouter $router, ExtensionPoints $points): void
    {
        SchoolSettingRoutes::register($router, new SchoolSettingsController());
    }
}
