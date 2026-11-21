<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Modules\SchoolYears\SchoolYear;

/**
 * Eine Lehrkraft-Zuordnung zu einer Fachgruppe, wie sie die Ausgaben von {@see TeacherAssignments} und die Antworten der
 * API zeigen (GLOSSARY.md: Lehrkraft-Zuordnung): Kennung, Fachgruppe, Lehrkraft, Zeitraum und der abgeleitete Status
 * (`upcoming`, `running`, `ended`; nie gespeichert).
 */
final class TeacherAssignment
{
    public function __construct(
        public readonly string $id,
        public readonly string $teachingGroupId,
        public readonly Teacher $teacher,
        public readonly AssignmentPeriod $period,
        public readonly string $status,
        private readonly SchoolYear $year,
    ) {
    }

    /** @return array{id:string,teaching_group_id:string,teacher:array{id:string,login_name:string,email:?string,status:string},starts_on:string,ends_on:?string,last_day:string,status:string} die JSON-Form der API: `ends_on` null = offen, `last_day` ist dann das Schuljahresende */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'teaching_group_id' => $this->teachingGroupId,
            'teacher' => $this->teacher->toArray(),
            ...$this->period->toArray(),
            'last_day' => $this->period->lastDay($this->year),
            'status' => $this->status,
        ];
    }
}
