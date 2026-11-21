<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Bootstrap\ModuleRegistration;
use TeacherOS\Modules\SchoolYears\SchoolYearGuards;
use TeacherOS\Modules\TeachingGroups\TeachingGroupLinkGuards;
use TeacherOS\Shared\Clock\Clock;
use TeacherOS\Shared\Clock\SystemClock;
use TeacherOS\Shared\Extension\ExtensionPoints;

/**
 * Anmeldung der Lehrkraft-Zuordnung zu Fachgruppen (Ticket #67) an einer Stelle: die Routen und der Einwand gegen das
 * Aendern eines Schuljahres, aus dem Zuordnungen herausfielen ({@see TeacherAssignmentsSchoolYearGuard}), und die Verknuepfung
 * "Fachgruppe mit Zuordnungen" ({@see TeacherAssignmentsLinkGuard}): eine solche Fachgruppe wird nicht geloescht, sondern archiviert.
 */
final class TeacherAssignmentsModule implements ModuleRegistration
{
    public function register(ApiRouter $router, ExtensionPoints $points): void
    {
        $points->get(SchoolYearGuards::class)->register(new TeacherAssignmentsSchoolYearGuard());
        $points->get(TeachingGroupLinkGuards::class)->register(new TeacherAssignmentsLinkGuard());
        TeacherAssignmentRoutes::register($router, $this->controller($points));
    }

    /** Der Controller mit dem Dienst dieser Anwendung; auch fuer die Probe-Routen der Tests, dort mit fester Uhr. */
    public function controller(ExtensionPoints $points, Clock $clock = new SystemClock()): TeacherAssignmentsController
    {
        return new TeacherAssignmentsController(new TeacherAssignments(rules: new AssignmentRules($clock)));
    }
}
