<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Endpunkte der Lehrkraft-Zuordnung zu Fachgruppen (Ticket #67). Modulpruefung und Rechte (`teaching_groups.view`,
 * `teacher_assignments.manage`) stehen an der Route ({@see TeacherAssignmentRoutes}); Fehler mit Code uebersetzt der
 * ApiRouter. Den Dienst bringt das Modul mit ({@see TeacherAssignmentsModule}).
 */
final class TeacherAssignmentsController
{
    public function __construct(private readonly TeacherAssignments $assignments)
    {
    }

    public function index(Request $request, Response $response): void
    {
        $groupId = self::string($request->getQuery('teaching_group_id'), 'die Fachgruppe (teaching_group_id)');
        $response->json(['teacher_assignments' => array_map(static fn (TeacherAssignment $a): array => $a->toArray(), $this->assignments->forGroup($groupId))]);
    }

    /** Die waehlbaren Lehrkraefte fuer das Formular. */
    public function teachers(Request $request, Response $response): void
    {
        $response->json(['teachers' => array_map(static fn (Teacher $teacher): array => $teacher->toArray(), $this->assignments->selectableTeachers())]);
    }

    public function create(Request $request, Response $response): void
    {
        $assignment = $this->assignments->create(
            AuthenticationMiddleware::actor(),
            self::string($request->getBody('teaching_group_id'), 'die Fachgruppe (teaching_group_id)'),
            self::string($request->getBody('teacher_id'), 'die Lehrkraft (teacher_id)'),
            $request->getBody('starts_on'),
            $request->getBody('ends_on'),
        );
        $response->json(['teacher_assignment' => $assignment->toArray()], 201);
    }

    public function end(Request $request, Response $response): void
    {
        $assignment = $this->assignments->end(AuthenticationMiddleware::actor(), (string) $request->getParam('id'), $request->getBody('ends_on'));
        $response->json(['teacher_assignment' => $assignment->toArray()]);
    }

    public function delete(Request $request, Response $response): void
    {
        $id = (string) $request->getParam('id');
        $this->assignments->delete(AuthenticationMiddleware::actor(), $id);
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
