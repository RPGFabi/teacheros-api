<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Geprueftes Anlegen einer Fachgruppe (GLOSSARY.md: Fachgruppe): Schuljahr und Fach plus die Regeln von
 * {@see TeachingGroupAttributes} (Bezeichnung und mindestens eine Klasse). Ob es Schuljahr, Fach und Klassen gibt und ob sie
 * waehlbar sind, prueft {@see TeachingGroups}.
 */
final class TeachingGroupInput
{
    private function __construct(
        public readonly string $schoolYearId,
        public readonly string $subjectId,
        public readonly TeachingGroupAttributes $attributes,
    ) {
    }

    /** @throws CodedException `invalid_request` (400) */
    public static function fromInput(mixed $schoolYearId, mixed $subjectId, mixed $name, mixed $classIds): self
    {
        if (!is_string($schoolYearId) || $schoolYearId === '') {
            throw new CodedException('invalid_request', 400, 'Bitte das Schuljahr der Fachgruppe angeben.');
        }
        if (!is_string($subjectId) || $subjectId === '') {
            throw new CodedException('invalid_request', 400, 'Bitte das Fach der Fachgruppe angeben.');
        }
        return new self($schoolYearId, $subjectId, TeachingGroupAttributes::fromInput($name, $classIds));
    }
}
