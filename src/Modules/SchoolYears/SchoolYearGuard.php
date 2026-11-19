<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

/**
 * Erweiterungspunkt (Source of Truth: Erweiterbarkeit): Ein Modul, das an Schuljahre anknuepft (Klassen, spaeter
 * weitere Stammdaten), haengt eine eigene Pruefung an, ohne den Ablauf in {@see SchoolYears} umzuschreiben. Die
 * Pruefung gibt einen Einwand zurueck oder null, wenn sie nichts einzuwenden hat. Angemeldet wird sie mit
 * {@see SchoolYearGuards::register()}; der erste Einwand gewinnt, die Aenderung oder das Loeschen unterbleibt dann.
 *
 * Die Pruefungen laufen unter der Schuljahr-Sperre und in der Transaktion der Aenderung, sehen also den Stand, auf dem
 * geschrieben wird.
 */
interface SchoolYearGuard
{
    /**
     * Einwand gegen die Aenderung des Zeitraums.
     *
     * @param array{id:string,name:string,starts_on:string,ends_on:string,status:string} $year das Schuljahr vor der Aenderung
     */
    public function objectionToUpdate(array $year, SchoolYearPeriod $newPeriod): ?SchoolYearObjection;

    /**
     * Einwand gegen das Loeschen.
     *
     * @param array{id:string,name:string,starts_on:string,ends_on:string,status:string} $year
     */
    public function objectionToDeletion(array $year): ?SchoolYearObjection;
}
