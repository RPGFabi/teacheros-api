<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

/**
 * Ein Fach (GLOSSARY.md: Fach) als Ausgabe des Moduls: Kennung, unveraenderliches Kuerzel und Bezeichnung. Andere Module
 * (Fachgruppen, Zuordnungen) bekommen diesen Typ von {@see Subjects}, kein Array.
 */
final class Subject implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
    ) {
    }

    /** @return array{id:string,code:string,name:string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name];
    }

    /** @return array{id:string,code:string,name:string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
