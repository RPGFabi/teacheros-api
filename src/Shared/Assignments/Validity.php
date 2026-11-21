<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Assignments;

use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Gueltigkeitszeitraum einer Zuordnung (GLOSSARY.md: Gueltigkeitszeitraum), so wie er gespeichert wird (Source of Truth:
 * `valid_from`/`valid_until`): der erste Tag und die EXKLUSIVE Obergrenze, also der Tag nach dem letzten Tag. Ohne
 * Obergrenze ist das Ende offen und heisst "bis zum Ende des Schuljahres"; es folgt dem Schuljahr, das der Aufrufer
 * mitgibt. Alle Tage als `JJJJ-MM-TT`. Dieselbe Form tragen Lehrkraft-Zuordnungen zu Fachgruppen (#67) und Klassenleitungen (#68).
 */
final class Validity
{
    public function __construct(
        public readonly string $validFrom,
        public readonly ?string $validUntil,
    ) {
    }

    /** Der Tag nach $day. */
    public static function nextDay(string $day): string
    {
        return (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    }

    /** Der Tag vor $day. */
    public static function previousDay(string $day): string
    {
        return (new \DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Ein Kalendertag aus der Eingabe.
     *
     * @throws CodedException `invalid_request` (400), wenn kein echter Kalendertag im Format `JJJJ-MM-TT`
     */
    public static function day(mixed $value, string $label): string
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new CodedException('invalid_request', 400, "Bitte {$label} als Kalendertag im Format JJJJ-MM-TT angeben.");
        }
        return $value;
    }

    /** Der letzte Tag, an dem die Zuordnung gilt (einschliesslich); bei offenem Ende der letzte Tag des Schuljahres. */
    public function lastDay(SchoolYear $year): string
    {
        return $this->validUntil === null ? $year->endsOn : self::previousDay($this->validUntil);
    }

    /** Die Obergrenze (exklusiv), bei offenem Ende der Tag nach dem Schuljahresende. */
    public function untilExclusive(SchoolYear $year): string
    {
        return $this->validUntil ?? self::nextDay($year->endsOn);
    }

    /** Hat die Zuordnung am Tag $day schon begonnen (der Beginn ist $day oder davor)? Dann hat sie "schon gegolten". */
    public function startedBy(string $day): bool
    {
        return $this->validFrom <= $day;
    }

    /** Gilt die Zuordnung an mindestens einem gemeinsamen Tag mit $other (beide im selben Schuljahr)? */
    public function overlaps(self $other, SchoolYear $year): bool
    {
        return $this->validFrom < $other->untilExclusive($year) && $other->validFrom < $this->untilExclusive($year);
    }

    /** Gilt die Zuordnung am Tag $day? */
    public function coversDay(string $day, SchoolYear $year): bool
    {
        return $this->validFrom <= $day && $day < $this->untilExclusive($year);
    }

    /**
     * Liegt der Gueltigkeitszeitraum innerhalb eines Schuljahres mit diesem ersten und letzten Tag? Ein offenes Ende folgt dem
     * Schuljahresende und passt daher immer, solange der Beginn innerhalb liegt.
     */
    public function fitsWithin(string $yearStartsOn, string $yearEndsOn): bool
    {
        return $this->validFrom >= $yearStartsOn
            && $this->validFrom <= $yearEndsOn
            && ($this->validUntil === null || $this->validUntil <= self::nextDay($yearEndsOn));
    }

    /** @return array{starts_on:string,ends_on:?string} die JSON-Form: erster und letzter Tag (einschliesslich), offenes Ende als null */
    public function toArray(): array
    {
        return ['starts_on' => $this->validFrom, 'ends_on' => $this->validUntil === null ? null : self::previousDay($this->validUntil)];
    }
}
