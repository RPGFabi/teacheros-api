<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Bootstrap\ModuleRegistration;
use TeacherOS\Modules\SchoolYears\SchoolYearGuards;
use TeacherOS\Shared\Clock\Clock;
use TeacherOS\Shared\Clock\SystemClock;
use TeacherOS\Shared\Extension\ExtensionPoints;

/**
 * Anmeldung der Lehrkraft-Zuordnung zu Fachgruppen (Ticket #67) an einer Stelle: die Routen und der Einwand gegen das
 * Aendern eines Schuljahres, aus dem Zuordnungen herausfielen ({@see TeacherAssignmentsSchoolYearGuard}). Die Verknuepfungs-
 * pruefung "Fachgruppe mit Zuordnungen" meldet #65 an, sobald es den Haken der Fachgruppen gibt.
 */
final class TeacherAssignmentsModule implements ModuleRegistration
{
    public function register(ApiRouter $router, ExtensionPoints $points): void
    {
        $points->get(SchoolYearGuards::class)->register(new TeacherAssignmentsSchoolYearGuard());
        TeacherAssignmentRoutes::register($router, $this->controller($points));
    }

    /** Der Controller mit dem Dienst dieser Anwendung; auch fuer die Probe-Routen der Tests, dort mit fester Uhr. */
    public function controller(ExtensionPoints $points, Clock $clock = new SystemClock()): TeacherAssignmentsController
    {
        return new TeacherAssignmentsController(new TeacherAssignments(rules: new AssignmentRules($clock)));
    }
}
