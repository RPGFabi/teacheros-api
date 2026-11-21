<?php

declare(strict_types=1);

namespace TeacherOS\Modules\ClassLeaders;

use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYearGuard;
use TeacherOS\Modules\SchoolYears\SchoolYearObjection;
use TeacherOS\Modules\SchoolYears\SchoolYearSpan;

/**
 * Einwand der Klassenleitungen gegen das Aendern des Zeitraums eines Schuljahres (Ticket #68): Ein Zeitraum, aus dem
 * Klassenleitungen herausfielen (Beginn ausserhalb, festes Ende nach dem neuen letzten Tag), wird abgelehnt, nicht still
 * gekuerzt (GLOSSARY.md: Gueltigkeitszeitraum liegt immer im Schuljahr). Eine offene Klassenleitung folgt dem Schuljahresende.
 * Das Loeschen eines Schuljahres sperren schon die Klassen, an denen jede Klassenleitung haengt.
 *
 * Wie bei den Lehrkraft-Zuordnungen (`TeacherAssignmentsSchoolYearGuard`) sperrt die Pruefung zuerst die Zeile des Schuljahres und
 * liest die Klassenleitungen aktuell: eine gleichzeitig angelegte Klassenleitung ({@see ClassLeaders::create()} sperrt die Zeile
 * gemeinsam) wird so gesehen.
 */
final class ClassLeadersSchoolYearGuard implements SchoolYearGuard
{
    public const CODE = 'school_year_has_class_leaders';

    public function __construct(private readonly ClassLeaderRepository $repository = new ClassLeaderRepository())
    {
    }

    public function objectionToUpdate(SchoolYear $year, SchoolYearSpan $newSpan): ?SchoolYearObjection
    {
        $this->repository->lockSchoolYear($year->id, exclusive: true);
        if (!$this->repository->anyOutsideSchoolYear($year->id, $newSpan->startsOn, $newSpan->endsOn)) {
            return null;
        }
        return new SchoolYearObjection(self::CODE, 'Im neuen Zeitraum würden Klassenleitungen herausfallen. Bitte zuerst die Klassenleitungen beenden oder löschen.');
    }

    public function objectionToDeletion(SchoolYear $year): ?SchoolYearObjection
    {
        return null;
    }
}
