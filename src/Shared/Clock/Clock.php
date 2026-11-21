<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Clock;

/**
 * Die Uhr der Schule: liefert "heute" als Kalendertag (`JJJJ-MM-TT`). Fachregeln, die vom heutigen Datum abhaengen
 * (z. B. "hat schon gegolten"), bekommen sie injiziert, damit Tests mit festem Datum laufen ({@see FixedClock}) und
 * nicht an einem bestimmten Tag kippen.
 */
interface Clock
{
    /** Der heutige Kalendertag der Schule als `JJJJ-MM-TT`. */
    public function today(): string;
}
