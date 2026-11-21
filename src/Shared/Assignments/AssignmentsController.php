<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Assignments;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Endpunkte der Zuordnungen einer {@see AssignmentKind} (Lehrkraft-Zuordnung zu Fachgruppen #67, Klassenleitung #68): Liste
 * eines Elternobjekts, Anlegen, Beenden, Loeschen und die Liste der waehlbaren Lehrkraefte. Modulpruefung und Rechte stehen an
 * der Route des jeweiligen Moduls (`TeacherAssignmentRoutes`, `ClassLeaderRoutes`); Fehler mit Code uebersetzt der ApiRouter.
 * Schluessel in Query, Body und Antwort sowie die Texte kommen aus der Art der Zuordnung.
 */
final class AssignmentsController
{
    public function __construct(
        private readonly Assignments $assignments,
        private readonly AssignmentKind $kind,
    ) {
    }

    public function index(Request $request, Response $response): void
    {
        $parentId = self::string($request->getQuery($this->kind->parentColumn), $this->kind->parentLabel);
        $response->json([$this->kind->collectionKey => array_map(static fn (AssignmentView $a): array => $a->toArray(), $this->assignments->forParent($parentId))]);
    }

    /** Die waehlbaren Lehrkraefte fuer das Formular (mit E-Mail und Kontostatus). */
    public function teachers(Request $request, Response $response): void
    {
        $response->json(['teachers' => array_map(static fn (Teacher $teacher): array => $teacher->toArray(), $this->assignments->selectableTeachers())]);
    }

    public function create(Request $request, Response $response): void
    {
        $assignment = $this->assignments->create(
            AuthenticationMiddleware::actor(),
            self::string($request->getBody($this->kind->parentColumn), $this->kind->parentLabel),
            self::string($request->getBody('teacher_id'), 'die Lehrkraft (teacher_id)'),
            $request->getBody('starts_on'),
            $request->getBody('ends_on'),
        );
        $response->json([$this->kind->objectType => $assignment->toArray()], 201);
    }

    public function end(Request $request, Response $response): void
    {
        $assignment = $this->assignments->end(AuthenticationMiddleware::actor(), (string) $request->getParam('id'), $request->getBody('ends_on'));
        $response->json([$this->kind->objectType => $assignment->toArray()]);
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
