<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use Hoelzle\Core\Database\DB;

/** Liest und schreibt die Tabelle `classes`. Regeln (Eingabe, Schuljahr, Sortierung) liegen nicht hier. */
final class ClassRepository
{
    /**
     * Die Klassen eines Schuljahres, unsortiert.
     *
     * @return list<array{id:string,grade_level:int,name:string}>
     */
    public function inSchoolYear(string $schoolYearId): array
    {
        $stmt = DB::getInstance()->prepare('SELECT `id`, `grade_level`, `name` FROM `classes` WHERE `school_year_id` = :year');
        $stmt->execute(['year' => $schoolYearId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => ['id' => $r['id'], 'grade_level' => (int) $r['grade_level'], 'name' => $r['name']], $rows);
    }

    /** @throws \PDOException bei doppelter Bezeichnung im Schuljahr (Fehler 1062) */
    public function insert(string $id, ClassInput $input): void
    {
        DB::getInstance()
            ->prepare('INSERT INTO `classes` (`id`, `school_year_id`, `grade_level`, `name`, `name_key`) VALUES (:id, :year, :grade, :name, :key)')
            ->execute(['id' => $id, 'year' => $input->schoolYearId, 'grade' => $input->gradeLevel, 'name' => $input->name, 'key' => $input->nameKey()]);
    }
}
