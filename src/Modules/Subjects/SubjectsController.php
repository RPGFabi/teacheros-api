<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Endpunkte der Faecher (Tickets #62 und #63). Modulpruefung und Rechte (`subjects.view`, `subjects.manage`) stehen an der Route
 * ({@see SubjectRoutes}); Fehler mit Code uebersetzt der ApiRouter. Den Dienst bringt das Modul mit ({@see SubjectsModule}).
 */
final class SubjectsController
{
    public function __construct(private readonly Subjects $subjects)
    {
    }

    public function index(Request $request, Response $response): void
    {
        $response->json(['subjects' => $this->subjects->list(self::flag($request->getQuery('include_archived')))]);
    }

    /** Nur waehlbare Faecher (ohne archivierte), fuer spaetere Zuordnungen. */
    public function selectable(Request $request, Response $response): void
    {
        $response->json(['subjects' => $this->subjects->selectable()]);
    }

    public function create(Request $request, Response $response): void
    {
        $input = SubjectInput::fromInput($request->getBody('code'), $request->getBody('name'));
        $response->json(['subject' => $this->subjects->create(AuthenticationMiddleware::actor(), $input)], 201);
    }

    public function update(Request $request, Response $response): void
    {
        $subject = $this->subjects->update(AuthenticationMiddleware::actor(), (string) $request->getParam('id'), $request->getBody('name'), $request->getBody('code'));
        $response->json(['subject' => $subject]);
    }

    public function archive(Request $request, Response $response): void
    {
        $response->json(['subject' => $this->subjects->archive(AuthenticationMiddleware::actor(), (string) $request->getParam('id'))]);
    }

    public function restore(Request $request, Response $response): void
    {
        $response->json(['subject' => $this->subjects->restore(AuthenticationMiddleware::actor(), (string) $request->getParam('id'))]);
    }

    public function delete(Request $request, Response $response): void
    {
        $id = (string) $request->getParam('id');
        $this->subjects->delete(AuthenticationMiddleware::actor(), $id);
        $response->json(['deleted' => true, 'id' => $id]);
    }

    /** Filter wie `include_archived=1` oder `=true`; alles andere (auch fehlend) ist aus. */
    private static function flag(mixed $value): bool
    {
        return $value === '1' || $value === 'true';
    }
}
