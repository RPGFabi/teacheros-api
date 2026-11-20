<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Endpunkte der Klassen (Tickets #55 und #57). Modulpruefung und Rechte (`classes.view`, `classes.manage`) stehen an der
 * Route ({@see ClassRoutes}); Fehler mit Code uebersetzt der ApiRouter. Den Dienst bringt das Modul mit ({@see ClassesModule}).
 */
final class ClassesController
{
    public function __construct(private readonly Classes $classes)
    {
    }

    public function index(Request $request, Response $response): void
    {
        $schoolYearId = $request->getQuery('school_year_id');
        if (!is_string($schoolYearId) || $schoolYearId === '') {
            throw new CodedException('invalid_request', 400, 'Bitte das Schuljahr angeben (school_year_id).');
        }
        $response->json(['classes' => $this->classes->listIn($schoolYearId, self::flag($request->getQuery('include_archived')))]);
    }

    /** Nur waehlbare Klassen (ohne archivierte), eines Schuljahres (school_year_id) oder aller Schuljahre. */
    public function selectable(Request $request, Response $response): void
    {
        $schoolYearId = $request->getQuery('school_year_id');
        if ($schoolYearId !== null && (!is_string($schoolYearId) || $schoolYearId === '')) {
            throw new CodedException('invalid_request', 400, 'Bitte eine gültige Schuljahr-Kennung angeben (school_year_id).');
        }
        $response->json(['classes' => $this->classes->selectable($schoolYearId)]);
    }

    public function schoolYears(Request $request, Response $response): void
    {
        $response->json(['school_years' => $this->classes->schoolYears()]);
    }

    public function create(Request $request, Response $response): void
    {
        $input = ClassInput::fromInput($request->getBody('school_year_id'), $request->getBody('grade_level'), $request->getBody('name'));
        $response->json(['class' => $this->classes->create(AuthenticationMiddleware::actor(), $input)], 201);
    }

    public function update(Request $request, Response $response): void
    {
        $class = $this->classes->update(
            AuthenticationMiddleware::actor(),
            (string) $request->getParam('id'),
            $request->getBody('grade_level'),
            $request->getBody('name'),
            $request->getBody('school_year_id'),
        );
        $response->json(['class' => $class]);
    }

    public function archive(Request $request, Response $response): void
    {
        $response->json(['class' => $this->classes->archive(AuthenticationMiddleware::actor(), (string) $request->getParam('id'))]);
    }

    public function restore(Request $request, Response $response): void
    {
        $response->json(['class' => $this->classes->restore(AuthenticationMiddleware::actor(), (string) $request->getParam('id'))]);
    }

    public function delete(Request $request, Response $response): void
    {
        $id = (string) $request->getParam('id');
        $this->classes->delete(AuthenticationMiddleware::actor(), $id);
        $response->json(['deleted' => true, 'id' => $id]);
    }

    /** Filter wie `include_archived=1` oder `=true`; alles andere (auch fehlend) ist aus. */
    private static function flag(mixed $value): bool
    {
        return $value === '1' || $value === 'true';
    }
}
