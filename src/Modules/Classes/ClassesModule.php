<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Bootstrap\ModuleRegistration;
use TeacherOS\Modules\SchoolYears\SchoolYearGuards;
use TeacherOS\Shared\Extension\ExtensionPoints;

/**
 * Anmeldung der Klassen (Tickets #55 bis #57) an einer Stelle: die Routen und die Einwaende der Klassen gegen das Loeschen
 * eines Schuljahres ({@see ClassesSchoolYearGuard}). Der eigene Erweiterungspunkt {@see ClassLinkGuards} gehoert diesem
 * Modul; es reicht dieselbe Instanz an den Dienst weiter, bei der sich spaetere Module anmelden.
 */
final class ClassesModule implements ModuleRegistration
{
    public function register(ApiRouter $router, ExtensionPoints $points): void
    {
        $points->get(SchoolYearGuards::class)->register(new ClassesSchoolYearGuard());
        ClassRoutes::register($router, $this->controller($points));
    }

    /** Der Controller mit dem Dienst dieser Anwendung; auch fuer die Probe-Routen der Tests. */
    public function controller(ExtensionPoints $points): ClassesController
    {
        return new ClassesController(new Classes(linkGuards: $points->get(ClassLinkGuards::class)));
    }
}
