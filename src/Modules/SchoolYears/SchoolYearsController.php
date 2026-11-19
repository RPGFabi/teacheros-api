<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Endpunkte der Schuljahre (Ticket #54). Modulpruefung und Rechte (`school_years.view`, `school_years.manage`) stehen
 * an der Route ({@see SchoolYearRoutes}); Fehler mit Code uebersetzt der ApiRouter.
 */
final class SchoolYearsController
{
    public static function index(Request $request, Response $response): void
    {
        $response->json(['school_years' => (new SchoolYears())->list()]);
    }

    public static function create(Request $request, Response $response): void
    {
        // Eine mitgeschickte Bezeichnung wird ignoriert: sie ergibt sich allein aus dem Zeitraum.
        $period = SchoolYearPeriod::fromInput($request->getBody('starts_on'), $request->getBody('ends_on'));
        $year = (new SchoolYears())->create(AuthenticationMiddleware::actor(), $period);
        $response->json(['school_year' => $year], 201);
    }
}
