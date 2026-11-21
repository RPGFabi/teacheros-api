<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Modules\Classes\SchoolClass;
use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Endpunkte der Fachgruppen (Tickets #64, #65). Modulpruefung und Rechte (`teaching_groups.view`, `teaching_groups.manage`) stehen
 * an der Route ({@see TeachingGroupRoutes}); Fehler mit Code uebersetzt der ApiRouter. Den Dienst bringt das Modul mit
 * ({@see TeachingGroupsModule}).
 */
final class TeachingGroupsController
{
    public function __construct(private readonly TeachingGroups $teachingGroups)
    {
    }

    public function index(Request $request, Response $response): void
    {
        $response->json(['teaching_groups' => self::arrays($this->teachingGroups->listIn(self::schoolYearId($request), self::flag($request->getQuery('include_archived'))))]);
    }

    /** Nur waehlbare Fachgruppen (ohne archivierte), eines Schuljahres (school_year_id) oder aller Schuljahre. */
    public function selectable(Request $request, Response $response): void
    {
        $schoolYearId = $request->getQuery('school_year_id');
        if ($schoolYearId !== null && (!is_string($schoolYearId) || $schoolYearId === '')) {
            throw new CodedException('invalid_request', 400, 'Bitte eine gültige Schuljahr-Kennung angeben (school_year_id).');
        }
        $response->json(['teaching_groups' => self::arrays($this->teachingGroups->selectable($schoolYearId))]);
    }

    public function show(Request $request, Response $response): void
    {
        $response->json(['teaching_group' => $this->teachingGroups->get((string) $request->getParam('id'))->toArray()]);
    }

    public function schoolYears(Request $request, Response $response): void
    {
        $response->json(['school_years' => array_map(static fn (SchoolYear $year): array => $year->toArray(), $this->teachingGroups->schoolYears())]);
    }

    /** Die Auswahl fuer das Formular: waehlbare Faecher und die waehlbaren Klassen des Schuljahres. */
    public function choices(Request $request, Response $response): void
    {
        $classes = $this->teachingGroups->selectableClasses(self::schoolYearId($request));
        $response->json([
            'subjects' => $this->teachingGroups->selectableSubjects(),
            'classes' => array_map(static fn (SchoolClass $class): array => $class->toArray(), $classes),
        ]);
    }

    public function create(Request $request, Response $response): void
    {
        $input = TeachingGroupInput::fromInput($request->getBody('school_year_id'), $request->getBody('subject_id'), $request->getBody('name'), $request->getBody('class_ids'));
        $response->json(['teaching_group' => $this->teachingGroups->create(AuthenticationMiddleware::actor(), $input)->toArray()], 201);
    }

    public function update(Request $request, Response $response): void
    {
        $group = $this->teachingGroups->update(
            AuthenticationMiddleware::actor(),
            (string) $request->getParam('id'),
            $request->getBody('name'),
            $request->getBody('class_ids'),
            $request->getBody('school_year_id'),
            $request->getBody('subject_id'),
        );
        $response->json(['teaching_group' => $group->toArray()]);
    }

    public function archive(Request $request, Response $response): void
    {
        $response->json(['teaching_group' => $this->teachingGroups->archive(AuthenticationMiddleware::actor(), (string) $request->getParam('id'))->toArray()]);
    }

    public function restore(Request $request, Response $response): void
    {
        $response->json(['teaching_group' => $this->teachingGroups->restore(AuthenticationMiddleware::actor(), (string) $request->getParam('id'))->toArray()]);
    }

    public function delete(Request $request, Response $response): void
    {
        $id = (string) $request->getParam('id');
        $this->teachingGroups->delete(AuthenticationMiddleware::actor(), $id);
        $response->json(['deleted' => true, 'id' => $id]);
    }

    /** Filter wie `include_archived=1` oder `=true`; alles andere (auch fehlend) ist aus. */
    private static function flag(mixed $value): bool
    {
        return $value === '1' || $value === 'true';
    }

    /** @throws CodedException `invalid_request` (400) */
    private static function schoolYearId(Request $request): string
    {
        $schoolYearId = $request->getQuery('school_year_id');
        if (!is_string($schoolYearId) || $schoolYearId === '') {
            throw new CodedException('invalid_request', 400, 'Bitte das Schuljahr angeben (school_year_id).');
        }
        return $schoolYearId;
    }

    /**
     * @param list<TeachingGroup> $groups
     * @return list<array<string,mixed>>
     */
    private static function arrays(array $groups): array
    {
        return array_map(static fn (TeachingGroup $group): array => $group->toArray(), $groups);
    }
}
