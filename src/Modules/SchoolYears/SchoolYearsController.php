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

    public static function update(Request $request, Response $response): void
    {
        $period = SchoolYearPeriod::fromInput($request->getBody('starts_on'), $request->getBody('ends_on'));
        $year = (new SchoolYears())->update(AuthenticationMiddleware::actor(), (string) $request->getParam('id'), $period);
        $response->json(['school_year' => $year]);
    }

    public static function delete(Request $request, Response $response): void
    {
        $id = (string) $request->getParam('id');
        (new SchoolYears())->delete(AuthenticationMiddleware::actor(), $id);
        $response->json(['deleted' => true, 'id' => $id]);
    }
}
