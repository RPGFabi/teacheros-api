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
 * Anmeldung der Fachgruppen (Tickets #64, #65) an einer Stelle: die Routen und die Pruefungen an den Erweiterungspunkten anderer
 * Module, naemlich der Einwand gegen das Loeschen eines Schuljahres mit Fachgruppen ({@see TeachingGroupsSchoolYearGuard}),
 * die Verknuepfung einer Klasse in einer Fachgruppe ({@see TeachingGroupsClassLinkGuard}) und die eines Faches
 * ({@see TeachingGroupsSubjectLinkGuard}). Die eigenen Erweiterungspunkte {@see TeachingGroupLinkGuards} (Verknuepfung einer
 * Fachgruppe, z. B. die Lehrkraft-Zuordnung) und {@see TeachingGroupClassRemovalGuards} (Einwand gegen das Entfernen einer
 * Klasse aus einer Fachgruppe) gehoeren diesem Modul; es reicht dieselben Instanzen an den Dienst weiter, bei denen sich
 * spaetere Module anmelden.
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
        return new TeachingGroupsController(new TeachingGroups(
            linkGuards: $points->get(TeachingGroupLinkGuards::class),
            classRemovalGuards: $points->get(TeachingGroupClassRemovalGuards::class),
        ));
    }
}
