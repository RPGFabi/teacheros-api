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
     * der Tag in einer Luecke ZWISCHEN zwei Schuljahren liegt, das naechste kommende. Vor dem ersten Schuljahr ist keines
     * laufend, das erste ist dann kommend; nach dem letzten ist alles vergangen. Hoechstens eines ist laufend, weil sich
     * Schuljahre nie ueberschneiden.
     *
     * @param list<array{0:string,1:string}> $spans Zeitraeume als [Beginn, Ende] (`JJJJ-MM-TT`), aufsteigend nach Beginn
     * @return list<self> in der Reihenfolge der Zeitraeume
     */
    public static function derive(array $spans, \DateTimeImmutable $today): array
    {
        $day = $today->format('Y-m-d');
        $nextUpcoming = null;
        foreach ($spans as $i => [$start]) {
            if ($start > $day && ($nextUpcoming === null || $start < $spans[$nextUpcoming][0])) {
                $nextUpcoming = $i;
            }
        }
        $containsToday = false;
        $anyEnded = false;
        foreach ($spans as [$start, $end]) {
            $containsToday = $containsToday || ($start <= $day && $day <= $end);
            $anyEnded = $anyEnded || $end < $day;
        }
        // In einer Luecke zwischen zwei Schuljahren: eines liegt davor (vergangen), das naechste dahinter.
        $inGap = !$containsToday && $anyEnded;

        $statuses = [];
        foreach ($spans as $i => [$start, $end]) {
            $statuses[] = match (true) {
                $start <= $day && $day <= $end => self::Running,
                $end < $day => self::Past,
                $i === $nextUpcoming && $inGap => self::Running,
                default => self::Upcoming,
            };
        }
        return $statuses;
    }
}
