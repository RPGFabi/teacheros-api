<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Geprueftes Anlegen einer Klasse (GLOSSARY.md: Klasse, Klassenstufe): Schuljahr plus die Regeln von {@see ClassAttributes}.
 */
final class ClassInput
{
    private function __construct(
        public readonly string $schoolYearId,
        public readonly ClassAttributes $attributes,
    ) {
    }

    /** @throws CodedException `invalid_request` (400) */
    public static function fromInput(mixed $schoolYearId, mixed $gradeLevel, mixed $name): self
    {
        if (!is_string($schoolYearId) || $schoolYearId === '') {
            throw new CodedException('invalid_request', 400, 'Bitte das Schuljahr der Klasse angeben.');
        }
        return new self($schoolYearId, ClassAttributes::fromInput($gradeLevel, $name));
    }
}
