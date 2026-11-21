<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Bootstrap\ModuleRegistration;
use TeacherOS\Shared\Extension\ExtensionPoints;

/**
 * Anmeldung der Faecher (Ticket #62): die Routen. Eigene Erweiterungspunkte hat das Modul noch nicht.
 */
final class SubjectsModule implements ModuleRegistration
{
    public function register(ApiRouter $router, ExtensionPoints $points): void
    {
        SubjectRoutes::register($router, $this->controller($points));
    }

    /** Der Controller mit dem Dienst dieser Anwendung; auch fuer die Probe-Routen der Tests. */
    public function controller(ExtensionPoints $points): SubjectsController
    {
        return new SubjectsController(new Subjects());
    }
}
