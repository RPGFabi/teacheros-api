<?php

declare(strict_types=1);

namespace TeacherOS\Modules\ClassLeaders;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Bootstrap\ModuleRegistration;
use TeacherOS\Modules\Classes\ClassLinkGuards;
use TeacherOS\Modules\SchoolYears\SchoolYearGuards;
use TeacherOS\Shared\Assignments\AssignmentRules;
use TeacherOS\Shared\Assignments\Assignments;
use TeacherOS\Shared\Assignments\AssignmentSchoolYearGuard;
use TeacherOS\Shared\Assignments\AssignmentsController;
use TeacherOS\Shared\Clock\Clock;
use TeacherOS\Shared\Clock\SystemClock;
use TeacherOS\Shared\Extension\ExtensionPoints;

/**
 * Anmeldung der Klassenleitung (Ticket #68) an einer Stelle: die Routen, der Einwand gegen das Aendern eines Schuljahres, aus dem
 * Klassenleitungen herausfielen ({@see AssignmentSchoolYearGuard}), und die Verknuepfung "Klasse mit Klassenleitung"
 * ({@see ClassLeadersClassLinkGuard}): eine solche Klasse wird nicht geloescht, sondern archiviert. Dienst, Speicher, Controller
 * und Schuljahr-Einwand sind die gemeinsamen der Zuordnungen ({@see ClassLeaderKind}).
 */
final class ClassLeadersModule implements ModuleRegistration
{
    public function register(ApiRouter $router, ExtensionPoints $points): void
    {
        $points->get(SchoolYearGuards::class)->register(new AssignmentSchoolYearGuard(ClassLeaderKind::get()));
        $points->get(ClassLinkGuards::class)->register(new ClassLeadersClassLinkGuard());
        ClassLeaderRoutes::register($router, $this->controller($points));
    }

    /** Der Controller mit dem Dienst dieser Anwendung; auch fuer die Probe-Routen der Tests, dort mit fester Uhr. */
    public function controller(ExtensionPoints $points, Clock $clock = new SystemClock()): AssignmentsController
    {
        $kind = ClassLeaderKind::get();
        return new AssignmentsController(new Assignments($kind, rules: new AssignmentRules($clock)), $kind);
    }
}
