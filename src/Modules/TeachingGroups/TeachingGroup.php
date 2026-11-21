<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Modules\Classes\SchoolClass;
use TeacherOS\Modules\SchoolYears\SchoolYearRef;
use TeacherOS\Modules\Subjects\Subject;

/**
 * Eine Fachgruppe, wie sie die Ausgaben von {@see TeachingGroups} und die Antworten der API zeigen (GLOSSARY.md:
 * Fachgruppe): Bezeichnung, das Schuljahr und das Fach, zu denen sie gehoert, und ihre Klassen (eine Menge, keine
 * Hauptklasse), nach Klassenstufe und natuerlicher Bezeichnung. Auch eine nach dem Verknuepfen archivierte Klasse bleibt
 * Teil der Fachgruppe und ist dann als archiviert markiert.
 */
final class TeachingGroup
{
    /** @param list<SchoolClass> $classes */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly SchoolYearRef $schoolYear,
        public readonly Subject $subject,
        public readonly array $classes,
    ) {
    }

    /** @return array{id:string,name:string,school_year:array{id:string,name:string},subject:array{id:string,code:string,name:string},classes:list<array{id:string,grade_level:int,name:string,archived:bool,school_year:array{id:string,name:string}}>} die JSON-Form der API */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'school_year' => $this->schoolYear->toArray(),
            'subject' => $this->subject->toArray(),
            'classes' => array_map(static fn (SchoolClass $class): array => $class->toArray(), $this->classes),
        ];
    }
}
