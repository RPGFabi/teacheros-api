<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Endpunkte der Klassen (Ticket #55). Modulpruefung und Rechte (`classes.view`, `classes.manage`) stehen an der Route
 * ({@see ClassRoutes}); Fehler mit Code uebersetzt der ApiRouter.
 */
final class ClassesController
{
    public static function index(Request $request, Response $response): void
    {
        $schoolYearId = $request->getQuery('school_year_id');
        if (!is_string($schoolYearId) || $schoolYearId === '') {
            throw new CodedException('invalid_request', 400, 'Bitte das Schuljahr angeben (school_year_id).');
        }
        $response->json(['classes' => (new Classes())->listIn($schoolYearId)]);
    }

    public static function schoolYears(Request $request, Response $response): void
    {
        $response->json(['school_years' => (new Classes())->schoolYears()]);
    }

    public static function create(Request $request, Response $response): void
    {
        $input = ClassInput::fromInput($request->getBody('school_year_id'), $request->getBody('grade_level'), $request->getBody('name'));
        $response->json(['class' => (new Classes())->create(AuthenticationMiddleware::actor(), $input)], 201);
    }
}
