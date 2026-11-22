<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

/**
 * Ein Schuljahr, wie es die Ausgaben von {@see SchoolYears} und die Antworten der API zeigen (GLOSSARY.md: Schuljahr):
 * Kennung, Bezeichnung und Zeitraum plus der aus dem Datum abgeleitete {@see SchoolYearStatus}. Bezeichnung und Status
 * werden nie gespeichert.
 */
final class SchoolYear
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly SchoolYearStatus $status,
    ) {
    }

    /** Der Verweis fuer Ausgaben, die ein Schuljahr nur mit Kennung und Bezeichnung nennen (z. B. bei der Klasse). */
    public function ref(): SchoolYearRef
    {
        return new SchoolYearRef($this->id, $this->name);
    }

    /** @return array{id:string,name:string,starts_on:string,ends_on:string,status:string} die JSON-Form der API */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn, 'status' => $this->status->value];
    }
}
