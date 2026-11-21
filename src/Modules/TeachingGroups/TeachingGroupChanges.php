<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Text\Text;

/**
 * Geprueftes Aendern einer Fachgruppe (Ticket #65, GLOSSARY.md: Fachgruppe) und der gemeinsame Teil des Anlegens
 * ({@see TeachingGroupInput}): die Bezeichnung und die Klassen. Die Bezeichnung ist frei gewaehlt, hat nach dem Trimmen 1 bis
 * 40 Zeichen und wird wie die Klassenbezeichnung Unicode-bewusst getrimmt und nach NFC normalisiert ({@see Text}); eindeutig
 * im Schuljahr ist sie ohne Gross-/Kleinschreibung ({@see self::nameKey()}). Die Klassen sind eine Menge: doppelte
 * Kennungen zaehlen einmal, mindestens eine. Ob es die Klassen gibt und ob sie waehlbar sind, prueft {@see TeachingGroups}.
 */
final class TeachingGroupChanges
{
    public const MAX_NAME_LENGTH = 40;

    /** @param list<string> $classIds ohne Doppelte, mindestens eine */
    private function __construct(
        public readonly string $name,
        public readonly array $classIds,
    ) {
    }

    /** @throws CodedException `invalid_request` (400) */
    public static function fromInput(mixed $name, mixed $classIds): self
    {
        $name = is_string($name) ? Text::clean($name) : '';
        $length = mb_strlen($name);
        if ($length < 1 || $length > self::MAX_NAME_LENGTH) {
            throw new CodedException('invalid_request', 400, 'Bitte eine Bezeichnung mit 1 bis ' . self::MAX_NAME_LENGTH . ' Zeichen angeben.');
        }
        if (!is_array($classIds) || !array_is_list($classIds) || $classIds === []) {
            throw new CodedException('invalid_request', 400, 'Bitte mindestens eine Klasse der Fachgruppe angeben (class_ids).');
        }
        foreach ($classIds as $classId) {
            if (!is_string($classId) || $classId === '') {
                throw new CodedException('invalid_request', 400, 'Bitte die Klassen als Liste von Kennungen angeben (class_ids).');
            }
        }
        return new self($name, array_values(array_unique($classIds)));
    }

    /** Schluessel der Eindeutigkeit je Schuljahr: die Bezeichnung ohne Ruecksicht auf Gross-/Kleinschreibung. */
    public function nameKey(): string
    {
        return Text::normalized(mb_strtolower($this->name));
    }
}
