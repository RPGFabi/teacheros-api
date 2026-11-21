<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

/** Verweis auf ein Schuljahr in der Ausgabe eines anderen Objekts: Kennung und Bezeichnung (siehe {@see SchoolYear::ref()}). */
final class SchoolYearRef
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
    }

    /** @return array{id:string,name:string} die JSON-Form der API */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
