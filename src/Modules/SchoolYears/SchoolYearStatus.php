<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

/**
 * Status eines Schuljahres (GLOSSARY.md: Schuljahr): laufend, kommend oder vergangen. Er folgt allein aus dem Datum,
 * wird nie gespeichert und nie von Hand umgeschaltet. Anzeigenamen (laufend, kommend, vergangen) vergibt das Web.
 */
enum SchoolYearStatus: string
{
    case Running = 'running';
    case Upcoming = 'upcoming';
    case Past = 'past';

    /**
     * Status je Zeitraum zum Stichtag. Laufend ist das Schuljahr, in das der Tag faellt (Grenzen inklusive), sonst, wenn
     * der Tag in einer Luecke oder vor dem ersten Schuljahr liegt, das naechste kommende. Hoechstens eines ist laufend,
     * weil sich Schuljahre nie ueberschneiden.
     *
     * @param list<array{0:string,1:string}> $periods Zeitraeume als [Beginn, Ende] (`JJJJ-MM-TT`), aufsteigend nach Beginn
     * @return list<self> in der Reihenfolge der Zeitraeume
     */
    public static function derive(array $periods, \DateTimeImmutable $today): array
    {
        $day = $today->format('Y-m-d');
        $nextUpcoming = null;
        foreach ($periods as $i => [$start]) {
            if ($start > $day && ($nextUpcoming === null || $start < $periods[$nextUpcoming][0])) {
                $nextUpcoming = $i;
            }
        }
        $containsToday = false;
        foreach ($periods as [$start, $end]) {
            $containsToday = $containsToday || ($start <= $day && $day <= $end);
        }

        $statuses = [];
        foreach ($periods as $i => [$start, $end]) {
            $statuses[] = match (true) {
                $start <= $day && $day <= $end => self::Running,
                $end < $day => self::Past,
                $i === $nextUpcoming && !$containsToday => self::Running,
                default => self::Upcoming,
            };
        }
        return $statuses;
    }
}
