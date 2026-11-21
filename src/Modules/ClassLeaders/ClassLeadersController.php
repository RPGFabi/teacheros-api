<?php

declare(strict_types=1);

namespace TeacherOS\Modules\ClassLeaders;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Endpunkte der Klassenleitung (Ticket #68). Modulpruefung und Rechte (`classes.view`, `teacher_assignments.manage`) stehen an
 * der Route ({@see ClassLeaderRoutes}); Fehler mit Code uebersetzt der ApiRouter. Den Dienst bringt das Modul mit.
 */
final class ClassLeadersController
{
    public function __construct(private readonly ClassLeaders $leaders)
    {
    }

    public function index(Request $request, Response $response): void
    {
        $classId = self::string($request->getQuery('class_id'), 'die Klasse (class_id)');
        $response->json(['class_leaders' => array_map(static fn (ClassLeader $l): array => $l->toArray(), $this->leaders->forClass($classId))]);
    }

    public function create(Request $request, Response $response): void
    {
        $leader = $this->leaders->create(
            AuthenticationMiddleware::actor(),
            self::string($request->getBody('class_id'), 'die Klasse (class_id)'),
            self::string($request->getBody('teacher_id'), 'die Lehrkraft (teacher_id)'),
            $request->getBody('starts_on'),
            $request->getBody('ends_on'),
        );
        $response->json(['class_leader' => $leader->toArray()], 201);
    }

    public function end(Request $request, Response $response): void
    {
        $leader = $this->leaders->end(AuthenticationMiddleware::actor(), (string) $request->getParam('id'), $request->getBody('ends_on'));
        $response->json(['class_leader' => $leader->toArray()]);
    }

    public function delete(Request $request, Response $response): void
    {
        $id = (string) $request->getParam('id');
        $this->leaders->delete(AuthenticationMiddleware::actor(), $id);
        $response->json(['deleted' => true, 'id' => $id]);
    }

    /** @throws CodedException `invalid_request` (400) */
    private static function string(mixed $value, string $what): string
    {
        if (!is_string($value) || $value === '') {
            throw new CodedException('invalid_request', 400, "Bitte {$what} angeben.");
        }
        return $value;
    }
}
