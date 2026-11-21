<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Text\Text;

/**
 * Geprueftes Anlegen einer Fachgruppe (GLOSSARY.md: Fachgruppe): Schuljahr, Fach sowie Bezeichnung und mindestens eine Klasse
 * nach den Regeln von {@see TeachingGroupChanges}. Ob es Schuljahr, Fach und Klassen gibt und ob sie waehlbar sind, prueft
 * {@see TeachingGroups}.
 */
final class TeachingGroupInput
{
    public const MAX_NAME_LENGTH = TeachingGroupChanges::MAX_NAME_LENGTH;

    /** @param list<string> $classIds ohne Doppelte, mindestens eine */
    private function __construct(
        public readonly string $schoolYearId,
        public readonly string $subjectId,
        public readonly string $name,
        public readonly array $classIds,
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
        $changes = TeachingGroupChanges::fromInput($name, $classIds);
        return new self($schoolYearId, $subjectId, $changes->name, $changes->classIds);
    }

    /** Schluessel der Eindeutigkeit je Schuljahr: die Bezeichnung ohne Ruecksicht auf Gross-/Kleinschreibung. */
    public function nameKey(): string
    {
        return Text::normalized(mb_strtolower($this->name));
    }
}
