<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

/**
 * Erweiterungspunkt (Source of Truth: Erweiterbarkeit): Ein Modul, das an Schuljahre anknuepft (Klassen, spaeter
 * weitere Stammdaten), haengt eine eigene Pruefung an, ohne den Ablauf in {@see SchoolYears} umzuschreiben. Die
 * Pruefung gibt einen Einwand zurueck oder null, wenn sie nichts einzuwenden hat. Das Modul meldet sie in seiner
 * Modul-Registrierung an ({@see SchoolYearGuards}); der erste Einwand gewinnt, die Aenderung oder das Loeschen
 * unterbleibt dann.
 *
 * Die Pruefungen laufen unter der Schuljahr-Sperre und in der Transaktion der Aenderung, sehen also den Stand, auf dem
 * geschrieben wird.
 */
interface SchoolYearGuard
{
    /**
     * Einwand gegen die Aenderung des Zeitraums.
     *
     * @param SchoolYear $year das Schuljahr vor der Aenderung
     */
    public function objectionToUpdate(SchoolYear $year, SchoolYearSpan $newSpan): ?SchoolYearObjection;

    /**
     * Einwand gegen das Loeschen.
     *
     * @param SchoolYear $year
     */
    public function objectionToDeletion(SchoolYear $year): ?SchoolYearObjection;
}
