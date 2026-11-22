<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use TeacherOS\Modules\SchoolYears\SchoolYearRef;

/**
 * Eine Klasse, wie sie die Ausgaben von {@see Classes} und die Antworten der API zeigen (GLOSSARY.md: Klasse): Stufe,
 * Bezeichnung, ob archiviert, und das Schuljahr, zu dem sie gehoert. (`Class` ist in PHP ein reserviertes Wort.)
 */
final class SchoolClass
{
    public function __construct(
        public readonly string $id,
        public readonly int $gradeLevel,
        public readonly string $name,
        public readonly bool $archived,
        public readonly SchoolYearRef $schoolYear,
    ) {
    }

    /** @return array{id:string,grade_level:int,name:string,archived:bool,school_year:array{id:string,name:string}} die JSON-Form der API */
    public function toArray(): array
    {
        return ['id' => $this->id, 'grade_level' => $this->gradeLevel, 'name' => $this->name, 'archived' => $this->archived, 'school_year' => $this->schoolYear->toArray()];
    }
}
