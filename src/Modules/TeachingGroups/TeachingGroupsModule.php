<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Bootstrap\ApiRouter;
use TeacherOS\Bootstrap\ModuleRegistration;
use TeacherOS\Modules\Classes\ClassLinkGuards;
use TeacherOS\Modules\SchoolYears\SchoolYearGuards;
use TeacherOS\Modules\Subjects\SubjectLinkGuards;
use TeacherOS\Shared\Extension\ExtensionPoints;

/**
 * Anmeldung der Fachgruppen (Ticket #64) an einer Stelle: die Routen und die Pruefungen an den Erweiterungspunkten anderer
 * Module, naemlich der Einwand gegen das Loeschen eines Schuljahres mit Fachgruppen ({@see TeachingGroupsSchoolYearGuard}),
 * die Verknuepfung einer Klasse in einer Fachgruppe ({@see TeachingGroupsClassLinkGuard}) und die eines Faches
 * ({@see TeachingGroupsSubjectLinkGuard}). Eigene Erweiterungspunkte hat das Modul noch nicht.
 */
final class TeachingGroupsModule implements ModuleRegistration
{
    public function register(ApiRouter $router, ExtensionPoints $points): void
    {
        $points->get(SchoolYearGuards::class)->register(new TeachingGroupsSchoolYearGuard());
        $points->get(ClassLinkGuards::class)->register(new TeachingGroupsClassLinkGuard());
        $points->get(SubjectLinkGuards::class)->register(new TeachingGroupsSubjectLinkGuard());
        TeachingGroupRoutes::register($router, $this->controller($points));
    }

    /** Der Controller mit dem Dienst dieser Anwendung; auch fuer die Probe-Routen der Tests. */
    public function controller(ExtensionPoints $points): TeachingGroupsController
    {
        return new TeachingGroupsController(new TeachingGroups());
    }
}
