<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Assignments;

use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYearGuard;
use TeacherOS\Modules\SchoolYears\SchoolYearObjection;
use TeacherOS\Modules\SchoolYears\SchoolYearSpan;
use TeacherOS\Shared\Clock\SystemClock;

/**
 * Einwand der Zuordnungen einer {@see AssignmentKind} (Lehrkraft-Zuordnungen #67, Klassenleitungen #68) gegen das Aendern des
 * Zeitraums eines Schuljahres. Abgelehnt, nicht still gekuerzt (GLOSSARY.md: Gueltigkeitszeitraum liegt immer im Schuljahr; was
 * schon gegolten hat, wird nie rueckwirkend veraendert), wird ein Zeitraum,
 * - aus dem Zuordnungen herausfielen: Beginn ausserhalb oder festes Ende nach dem neuen letzten Tag, und
 * - dessen neues Ende vor heute liegt, obwohl eine offene Zuordnung schon begonnen hat: sie gilt bis zum Schuljahresende, ein
 *   frueheres Ende nahme ihr bereits gegoltene Tage. Ein neues Ende ab heute laesst alles Gegoltene stehen.
 * Sonst folgt eine offene Zuordnung dem neuen Schuljahresende. Das Loeschen eines Schuljahres sperren schon die Elternobjekte
 * (Fachgruppen, Klassen), an denen jede Zuordnung haengt. "Heute" kommt aus der injizierten Uhr der {@see AssignmentRules}.
 *
 * Die Pruefung sperrt zuerst die Zeile des Schuljahres und liest die Zuordnungen aktuell: eine gleichzeitig angelegte
 * Zuordnung ({@see Assignments::create()} sperrt die Zeile gemeinsam) wird so gesehen.
 */
final class AssignmentSchoolYearGuard implements SchoolYearGuard
{
    private readonly AssignmentRepository $repository;
    private readonly AssignmentRules $rules;

    public function __construct(
        private readonly AssignmentKind $kind,
        ?AssignmentRepository $repository = null,
        ?AssignmentRules $rules = null,
    ) {
        $this->repository = $repository ?? new AssignmentRepository($kind);
        $this->rules = $rules ?? new AssignmentRules(new SystemClock());
    }

    public function objectionToUpdate(SchoolYear $year, SchoolYearSpan $newSpan): ?SchoolYearObjection
    {
        $this->repository->lockSchoolYear($year->id, exclusive: true);
        if ($this->repository->anyOutsideSchoolYear($year->id, $newSpan->startsOn, $newSpan->endsOn)) {
            return new SchoolYearObjection($this->kind->schoolYearCode, $this->kind->schoolYearOutsideMessage);
        }
        if ($this->rules->shorteningLosesValidDays($year->endsOn, $newSpan->endsOn) && $this->repository->anyOpenStartedBy($year->id, $this->rules->today())) {
            return new SchoolYearObjection($this->kind->schoolYearCode, $this->kind->schoolYearLossMessage);
        }
        return null;
    }

    public function objectionToDeletion(SchoolYear $year): ?SchoolYearObjection
    {
        return null;
    }
}
