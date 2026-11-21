<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use Hoelzle\Core\Database\DB;
use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYearGuard;
use TeacherOS\Modules\SchoolYears\SchoolYearObjection;
use TeacherOS\Modules\SchoolYears\SchoolYearSpan;

/**
 * Einwand der Fachgruppen gegen das Loeschen eines Schuljahres (Ticket #64): Ein Schuljahr mit Fachgruppen bleibt bestehen.
 * Das Aendern des Zeitraums bleibt mit Fachgruppen erlaubt. Der Fremdschluessel `teaching_groups.school_year_id` sichert
 * dasselbe auf der Datenbank ab; diese Pruefung liefert vorher den verstaendlichen Fehlercode. (Hat das Schuljahr auch
 * Klassen, was bei Fachgruppen immer der Fall ist, nennt der Einwand der Klassen den Grund zuerst.)
 */
final class TeachingGroupsSchoolYearGuard implements SchoolYearGuard
{
    public const CODE = 'school_year_has_teaching_groups';

    public function objectionToUpdate(SchoolYear $year, SchoolYearSpan $newSpan): ?SchoolYearObjection
    {
        return null;
    }

    public function objectionToDeletion(SchoolYear $year): ?SchoolYearObjection
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM `teaching_groups` WHERE `school_year_id` = :year LIMIT 1');
        $stmt->execute(['year' => $year->id]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }
        return new SchoolYearObjection(self::CODE, 'Das Schuljahr hat Fachgruppen und kann nicht gelöscht werden.');
    }
}
