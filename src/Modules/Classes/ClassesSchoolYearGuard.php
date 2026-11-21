<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use Hoelzle\Core\Database\DB;
use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYearGuard;
use TeacherOS\Modules\SchoolYears\SchoolYearObjection;
use TeacherOS\Modules\SchoolYears\SchoolYearSpan;

/**
 * Einwand der Klassen gegen das Loeschen eines Schuljahres (Ticket #56): Ein Schuljahr mit Klassen bleibt dauerhaft
 * bestehen (GLOSSARY.md: Schuljahr), und zwar mit jeder Klasse, auch einer archivierten. Das Aendern des Zeitraums
 * bleibt mit Klassen erlaubt. Der Fremdschluessel `classes.school_year_id` sichert dasselbe auf der Datenbank ab; diese
 * Pruefung liefert vorher den verstaendlichen Fehlercode.
 */
final class ClassesSchoolYearGuard implements SchoolYearGuard
{
    public const CODE = 'school_year_has_classes';

    public function objectionToUpdate(SchoolYear $year, SchoolYearSpan $newSpan): ?SchoolYearObjection
    {
        return null;
    }

    public function objectionToDeletion(SchoolYear $year): ?SchoolYearObjection
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM `classes` WHERE `school_year_id` = :year LIMIT 1');
        $stmt->execute(['year' => $year->id]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }
        return new SchoolYearObjection(self::CODE, 'Das Schuljahr hat Klassen (auch archivierte) und kann nicht gelöscht werden.');
    }
}
