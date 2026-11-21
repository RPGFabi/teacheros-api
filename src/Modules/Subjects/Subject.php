<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

/**
 * Ein Fach (GLOSSARY.md: Fach) als Ausgabe des Moduls: Kennung, unveraenderliches Kuerzel, Bezeichnung und ob es archiviert
 * ist. Andere Module (Fachgruppen, Zuordnungen) bekommen diesen Typ von {@see Subjects}, kein Array.
 */
final class Subject implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly bool $archived = false,
    ) {
    }

    /** @return array{id:string,code:string,name:string,archived:bool} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'archived' => $this->archived];
    }

    /** @return array{id:string,code:string,name:string,archived:bool} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
