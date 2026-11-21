<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Endpunkte der Schuljahre (Ticket #54). Modulpruefung und Rechte (`school_years.view`, `school_years.manage`) stehen
 * an der Route ({@see SchoolYearRoutes}); Fehler mit Code uebersetzt der ApiRouter. Den Dienst bringt das Modul mit
 * ({@see SchoolYearsModule}).
 */
final class SchoolYearsController
{
    public function __construct(private readonly SchoolYears $years)
    {
    }

    public function index(Request $request, Response $response): void
    {
        $response->json(['school_years' => array_map(static fn (SchoolYear $year): array => $year->toArray(), $this->years->list())]);
    }

    public function create(Request $request, Response $response): void
    {
        // Eine mitgeschickte Bezeichnung wird ignoriert: sie ergibt sich allein aus dem Zeitraum.
        $span = SchoolYearSpan::fromInput($request->getBody('starts_on'), $request->getBody('ends_on'));
        $response->json(['school_year' => $this->years->create(AuthenticationMiddleware::actor(), $span)->toArray()], 201);
    }

    public function update(Request $request, Response $response): void
    {
        $span = SchoolYearSpan::fromInput($request->getBody('starts_on'), $request->getBody('ends_on'));
        $year = $this->years->update(AuthenticationMiddleware::actor(), (string) $request->getParam('id'), $span);
        $response->json(['school_year' => $year->toArray()]);
    }

    public function delete(Request $request, Response $response): void
    {
        $id = (string) $request->getParam('id');
        $this->years->delete(AuthenticationMiddleware::actor(), $id);
        $response->json(['deleted' => true, 'id' => $id]);
    }
}
