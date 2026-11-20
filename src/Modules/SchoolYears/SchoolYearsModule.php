<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Bootstrap\ModuleRegistration;
use TeacherOS\Shared\Extension\ExtensionPoints;

/**
 * Anmeldung der Schuljahre (Tickets #54, #56): die Routen. Der Erweiterungspunkt {@see SchoolYearGuards} gehoert diesem
 * Modul; es reicht dieselbe Instanz an den Dienst weiter, bei der sich andere Module anmelden.
 */
final class SchoolYearsModule implements ModuleRegistration
{
    public function register(ApiRouter $router, ExtensionPoints $points): void
    {
        SchoolYearRoutes::register($router, $this->controller($points));
    }

    /** Der Controller mit dem Dienst dieser Anwendung; auch fuer die Probe-Routen der Tests. */
    public function controller(ExtensionPoints $points): SchoolYearsController
    {
        return new SchoolYearsController(new SchoolYears(guards: $points->get(SchoolYearGuards::class)));
    }
}
