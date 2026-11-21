<?php

declare(strict_types=1);

namespace TeacherOS\Modules\ClassLeaders;

use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\TeacherAssignments\AssignmentPeriod;
use TeacherOS\Modules\TeacherAssignments\Teacher;

/**
 * Eine Klassenleitung, wie sie die Ausgaben von {@see ClassLeaders} und die Antworten der API zeigen (GLOSSARY.md:
 * Klassenleitung): Kennung, Klasse, Lehrkraft, Zeitraum und der abgeleitete Status (`upcoming`, `running`, `ended`; nie gespeichert).
 */
final class ClassLeader
{
    public function __construct(
        public readonly string $id,
        public readonly string $classId,
        public readonly Teacher $teacher,
        public readonly AssignmentPeriod $period,
        public readonly string $status,
        private readonly SchoolYear $year,
    ) {
    }

    /** @return array{id:string,class_id:string,teacher:array{id:string,login_name:string,email:?string,status:string},starts_on:string,ends_on:?string,last_day:string,status:string} die JSON-Form der API: `ends_on` null = offen, `last_day` ist dann das Schuljahresende */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->classId,
            'teacher' => $this->teacher->toArray(),
            ...$this->period->toArray(),
            'last_day' => $this->period->lastDay($this->year),
            'status' => $this->status,
        ];
    }
}
