<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Bootstrap\ModuleRegistration;
use TeacherOS\Modules\SchoolYears\SchoolYearGuards;
use TeacherOS\Modules\TeachingGroups\TeachingGroupLinkGuards;
use TeacherOS\Shared\Assignments\AssignmentRules;
use TeacherOS\Shared\Assignments\Assignments;
use TeacherOS\Shared\Assignments\AssignmentSchoolYearGuard;
use TeacherOS\Shared\Assignments\AssignmentsController;
use TeacherOS\Shared\Clock\Clock;
use TeacherOS\Shared\Clock\SystemClock;
use TeacherOS\Shared\Extension\ExtensionPoints;

/**
 * Anmeldung der Lehrkraft-Zuordnung zu Fachgruppen (Ticket #67) an einer Stelle: die Routen, der Einwand gegen das Aendern
 * eines Schuljahres, aus dem Zuordnungen herausfielen ({@see AssignmentSchoolYearGuard}), und die Verknuepfung "Fachgruppe mit
 * Zuordnungen" ({@see TeacherAssignmentsLinkGuard}): eine solche Fachgruppe wird nicht geloescht, sondern archiviert. Dienst,
 * Speicher, Controller und Schuljahr-Einwand sind die gemeinsamen der Zuordnungen ({@see TeacherAssignmentKind}).
 */
final class TeacherAssignmentsModule implements ModuleRegistration
{
    public function register(ApiRouter $router, ExtensionPoints $points): void
    {
        $points->get(SchoolYearGuards::class)->register(new AssignmentSchoolYearGuard(TeacherAssignmentKind::get()));
        $points->get(TeachingGroupLinkGuards::class)->register(new TeacherAssignmentsLinkGuard());
        TeacherAssignmentRoutes::register($router, $this->controller($points));
    }

    /** Der Controller mit dem Dienst dieser Anwendung; auch fuer die Probe-Routen der Tests, dort mit fester Uhr. */
    public function controller(ExtensionPoints $points, Clock $clock = new SystemClock()): AssignmentsController
    {
        $kind = TeacherAssignmentKind::get();
        return new AssignmentsController(new Assignments($kind, rules: new AssignmentRules($clock)), $kind);
    }
}
