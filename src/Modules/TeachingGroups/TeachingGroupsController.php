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
 * Endpunkte der Fachgruppen (Ticket #64). Modulpruefung und Rechte (`teaching_groups.view`, `teaching_groups.manage`) stehen
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
        $response->json(['teaching_groups' => self::arrays($this->teachingGroups->listIn(self::schoolYearId($request)))]);
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
