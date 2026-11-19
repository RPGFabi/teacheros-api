<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die pruefbaren Eigenschaften einer Klasse (GLOSSARY.md: Klasse, Klassenstufe): Klassenstufe als ganze Zahl von 1 bis 13
 * und Bezeichnung, die nach dem Trimmen 1 bis 20 Zeichen hat. Gilt beim Anlegen ({@see ClassInput}) und beim Bearbeiten
 * gleichermassen; die Bezeichnung wird nicht gegen die Klassenstufe geprueft.
 */
final class ClassChange
{
    public const MIN_GRADE_LEVEL = 1;
    public const MAX_GRADE_LEVEL = 13;
    public const MAX_NAME_LENGTH = 20;

    private function __construct(
        public readonly int $gradeLevel,
        public readonly string $name,
    ) {
    }

    /** @throws CodedException `invalid_request` (400) */
    public static function fromInput(mixed $gradeLevel, mixed $name): self
    {
        if (!is_int($gradeLevel) || $gradeLevel < self::MIN_GRADE_LEVEL || $gradeLevel > self::MAX_GRADE_LEVEL) {
            throw new CodedException('invalid_request', 400, 'Bitte die Klassenstufe als ganze Zahl von ' . self::MIN_GRADE_LEVEL . ' bis ' . self::MAX_GRADE_LEVEL . ' angeben.');
        }
        $trimmed = is_string($name) ? trim($name) : '';
        $length = mb_strlen($trimmed);
        if ($length < 1 || $length > self::MAX_NAME_LENGTH) {
            throw new CodedException('invalid_request', 400, 'Bitte eine Bezeichnung mit 1 bis ' . self::MAX_NAME_LENGTH . ' Zeichen angeben.');
        }
        return new self($gradeLevel, $trimmed);
    }

    /** Schluessel der Eindeutigkeit je Schuljahr: die Bezeichnung ohne Ruecksicht auf Gross-/Kleinschreibung. */
    public function nameKey(): string
    {
        return mb_strtolower($this->name);
    }
}
