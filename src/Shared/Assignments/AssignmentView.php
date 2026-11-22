<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Assignments;

use TeacherOS\Modules\SchoolYears\SchoolYear;

/**
 * Eine Zuordnung (Lehrkraft-Zuordnung zu einer Fachgruppe oder Klassenleitung), wie sie die Ausgaben von {@see Assignments} und
 * die Antworten der API zeigen (GLOSSARY.md: Lehrkraft-Zuordnung, Klassenleitung): Kennung, Elternobjekt, Lehrkraft (nur Kennung
 * und Anmeldename, {@see TeacherRef}), Gueltigkeitszeitraum und der abgeleitete Status (`upcoming`, `running`, `ended`; nie
 * gespeichert). Der JSON-Schluessel des Elternobjekts (`teaching_group_id`, `class_id`) kommt aus der {@see AssignmentKind}.
 */
final class AssignmentView
{
    public function __construct(
        private readonly AssignmentKind $kind,
        public readonly string $id,
        public readonly string $parentId,
        public readonly TeacherRef $teacher,
        public readonly Validity $validity,
        public readonly string $status,
        private readonly SchoolYear $year,
    ) {
    }

    /** @return array<string,mixed> die JSON-Form der API: `{id, <Elternobjekt>_id, teacher: {id, login_name}, starts_on, ends_on, last_day, status}`; `ends_on` null = offen, `last_day` ist dann das Schuljahresende */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            $this->kind->parentColumn => $this->parentId,
            'teacher' => $this->teacher->toArray(),
            ...$this->validity->toArray(),
            'last_day' => $this->validity->lastDay($this->year),
            'status' => $this->status,
        ];
    }
}
