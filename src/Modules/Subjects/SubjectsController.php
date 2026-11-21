<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Endpunkte der Faecher (Ticket #62). Modulpruefung und Rechte (`subjects.view`, `subjects.manage`) stehen an der Route
 * ({@see SubjectRoutes}); Fehler mit Code uebersetzt der ApiRouter. Den Dienst bringt das Modul mit ({@see SubjectsModule}).
 */
final class SubjectsController
{
    public function __construct(private readonly Subjects $subjects)
    {
    }

    public function index(Request $request, Response $response): void
    {
        $response->json(['subjects' => $this->subjects->list()]);
    }

    /** Nur waehlbare Faecher, fuer spaetere Zuordnungen (vorerst alle). */
    public function selectable(Request $request, Response $response): void
    {
        $response->json(['subjects' => $this->subjects->selectable()]);
    }

    public function create(Request $request, Response $response): void
    {
        $input = SubjectInput::fromInput($request->getBody('code'), $request->getBody('name'));
        $response->json(['subject' => $this->subjects->create(AuthenticationMiddleware::actor(), $input)], 201);
    }
}
