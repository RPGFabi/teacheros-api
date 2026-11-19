<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die pruefbaren Eigenschaften einer Klasse (GLOSSARY.md: Klasse, Klassenstufe): Klassenstufe als ganze Zahl von 1 bis 13
 * und Bezeichnung, die nach dem Trimmen 1 bis 20 Zeichen hat. Getrimmt wird Unicode-bewusst (auch geschuetzte und andere
 * Leerzeichen, Zeilenumbrueche und unsichtbare Zeichen am Rand); eine Bezeichnung nur aus solchem Leerraum ist ungueltig.
 * Mit ext-intl wird die Bezeichnung zusaetzlich nach NFC normalisiert, damit "7ä" als ein Zeichen und als "a" plus
 * Trema dieselbe Bezeichnung ist; ohne ext-intl unterbleibt das (docs/workspace.md). Gilt beim Anlegen ({@see ClassInput}) und beim Bearbeiten
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
        $trimmed = is_string($name) ? self::normalized(self::trimmed($name)) : '';
        $length = mb_strlen($trimmed);
        if ($length < 1 || $length > self::MAX_NAME_LENGTH) {
            throw new CodedException('invalid_request', 400, 'Bitte eine Bezeichnung mit 1 bis ' . self::MAX_NAME_LENGTH . ' Zeichen angeben.');
        }
        return new self($gradeLevel, $trimmed);
    }

    /** Schluessel der Eindeutigkeit je Schuljahr: die Bezeichnung ohne Ruecksicht auf Gross-/Kleinschreibung. */
    public function nameKey(): string
    {
        return self::normalized(mb_strtolower($this->name));
    }

    /** Ohne Leerraum am Rand: Leerzeichen jeder Art (\p{Z}), Steuer- und Formatzeichen (\p{Cc}, \p{Cf}:Tabulator, Zeilenumbruch, Zero-Width-Space, BOM). Ungueltiges UTF-8 ergibt "". */
    private static function trimmed(string $name): string
    {
        return mb_check_encoding($name, 'UTF-8') ? (string) preg_replace('/^[\p{Z}\p{Cc}\p{Cf}]+|[\p{Z}\p{Cc}\p{Cf}]+$/u', '', $name) : '';
    }

    /** NFC, sofern ext-intl vorhanden ist; sonst unveraendert. */
    private static function normalized(string $text): string
    {
        return class_exists(\Normalizer::class) ? (\Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text) : $text;
    }
}
