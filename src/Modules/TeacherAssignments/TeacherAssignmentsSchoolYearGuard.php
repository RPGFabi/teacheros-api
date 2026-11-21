<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYearGuard;
use TeacherOS\Modules\SchoolYears\SchoolYearObjection;
use TeacherOS\Modules\SchoolYears\SchoolYearSpan;

/**
 * Einwand der Lehrkraft-Zuordnungen gegen das Aendern des Zeitraums eines Schuljahres (Ticket #67): Ein Zeitraum, aus dem
 * Zuordnungen herausfielen (Beginn ausserhalb, festes Ende nach dem neuen letzten Tag), wird abgelehnt, nicht still
 * gekuerzt (GLOSSARY.md: Gueltigkeitszeitraum liegt immer im Schuljahr). Eine offene Zuordnung folgt dem Schuljahresende.
 * Das Loeschen eines Schuljahres sperren schon die Fachgruppen, an denen jede Zuordnung haengt.
 *
 * Die Pruefung sperrt zuerst die Zeile des Schuljahres und liest die Zuordnungen aktuell: eine gleichzeitig angelegte
 * Zuordnung ({@see TeacherAssignments::create()} sperrt die Zeile gemeinsam) wird so gesehen.
 */
final class TeacherAssignmentsSchoolYearGuard implements SchoolYearGuard
{
    public const CODE = 'school_year_has_teacher_assignments';

    public function __construct(private readonly TeacherAssignmentRepository $repository = new TeacherAssignmentRepository())
    {
    }

    public function objectionToUpdate(SchoolYear $year, SchoolYearSpan $newSpan): ?SchoolYearObjection
    {
        $this->repository->lockSchoolYear($year->id, exclusive: true);
        if (!$this->repository->anyOutsideSchoolYear($year->id, $newSpan->startsOn, $newSpan->endsOn)) {
            return null;
        }
        return new SchoolYearObjection(self::CODE, 'Im neuen Zeitraum würden Lehrkraft-Zuordnungen von Fachgruppen herausfallen. Bitte zuerst die Zuordnungen beenden oder löschen.');
    }

    public function objectionToDeletion(SchoolYear $year): ?SchoolYearObjection
    {
        return null;
    }
}
