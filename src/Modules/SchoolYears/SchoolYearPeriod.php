<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Zeitraum eines Schuljahres (GLOSSARY.md: Schuljahr): erster und letzter Kalendertag, beide inklusive, als `JJJJ-MM-TT`.
 * Das Ende liegt im Kalenderjahr nach dem Beginn. Die Bezeichnung ergibt sich allein aus dem Zeitraum und wird nie
 * eingegeben: Jahr des Beginns, Schraegstrich, letzte zwei Ziffern des Endjahres ("2026/27").
 */
final class SchoolYearPeriod
{
    private function __construct(
        public readonly string $startsOn,
        public readonly string $endsOn,
    ) {
    }

    /**
     * Aus dem Request-Body: jeder Wert muss ein echter Kalendertag im Format `JJJJ-MM-TT` sein.
     *
     * @throws CodedException `invalid_request` (400), `invalid_period` (422)
     */
    public static function fromInput(mixed $startsOn, mixed $endsOn): self
    {
        return self::fromStrings(self::day($startsOn, 'Beginn'), self::day($endsOn, 'Ende'));
    }

    /** @throws CodedException `invalid_request` (400), `invalid_period` (422) */
    public static function fromStrings(string $startsOn, string $endsOn): self
    {
        $start = self::day($startsOn, 'Beginn');
        $end = self::day($endsOn, 'Ende');
        if ((int) substr($end, 0, 4) !== (int) substr($start, 0, 4) + 1) {
            throw new CodedException('invalid_period', 422, 'Das Ende muss im Kalenderjahr nach dem Beginn liegen.');
        }
        return new self($start, $end);
    }

    /** Bezeichnung "JJJJ/JJ", z. B. 14.09.2026 bis 29.07.2027 ergibt "2026/27". */
    public function name(): string
    {
        return substr($this->startsOn, 0, 4) . '/' . substr($this->endsOn, 2, 2);
    }

    private static function day(mixed $value, string $label): string
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new CodedException('invalid_request', 400, "Bitte {$label} als Kalendertag im Format JJJJ-MM-TT angeben.");
        }
        return $value;
    }
}
