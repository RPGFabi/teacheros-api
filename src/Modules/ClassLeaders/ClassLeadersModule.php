<?php

declare(strict_types=1);

namespace TeacherOS\Modules\ClassLeaders;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Bootstrap\ModuleRegistration;
use TeacherOS\Modules\Classes\ClassLinkGuards;
use TeacherOS\Modules\SchoolYears\SchoolYearGuards;
use TeacherOS\Modules\TeacherAssignments\AssignmentRules;
use TeacherOS\Shared\Clock\Clock;
use TeacherOS\Shared\Clock\SystemClock;
use TeacherOS\Shared\Extension\ExtensionPoints;

/**
 * Anmeldung der Klassenleitung (Ticket #68) an einer Stelle: die Routen, der Einwand gegen das Aendern eines Schuljahres, aus dem
 * Klassenleitungen herausfielen ({@see ClassLeadersSchoolYearGuard}), und die Verknuepfung "Klasse mit Klassenleitung"
 * ({@see ClassLeadersClassLinkGuard}): eine solche Klasse wird nicht geloescht, sondern archiviert.
 */
final class ClassLeadersModule implements ModuleRegistration
{
    public function register(ApiRouter $router, ExtensionPoints $points): void
    {
        $points->get(SchoolYearGuards::class)->register(new ClassLeadersSchoolYearGuard());
        $points->get(ClassLinkGuards::class)->register(new ClassLeadersClassLinkGuard());
        ClassLeaderRoutes::register($router, $this->controller($points));
    }

    /** Der Controller mit dem Dienst dieser Anwendung; auch fuer die Probe-Routen der Tests, dort mit fester Uhr. */
    public function controller(ExtensionPoints $points, Clock $clock = new SystemClock()): ClassLeadersController
    {
        return new ClassLeadersController(new ClassLeaders(rules: new AssignmentRules($clock)));
    }
}
