<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use Hoelzle\Core\Database\DB;

/** Liest und schreibt die Tabelle `classes`. Regeln (Eingabe, Schuljahr, Sortierung) liegen nicht hier. */
final class ClassRepository
{
    /**
     * Die Klassen eines Schuljahres, unsortiert; archivierte nur auf Wunsch.
     *
     * @return list<array{id:string,school_year_id:string,grade_level:int,name:string,archived:bool}>
     */
    public function inSchoolYear(string $schoolYearId, bool $includeArchived = false): array
    {
        $sql = 'SELECT `id`, `school_year_id`, `grade_level`, `name`, `archived_at` FROM `classes` WHERE `school_year_id` = :year'
            . ($includeArchived ? '' : ' AND `archived_at` IS NULL');
        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute(['year' => $schoolYearId]);
        return array_map(self::row(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Eine Klasse; mit $lock innerhalb einer Transaktion fuer die Dauer der Aenderung gesperrt.
     *
     * @return array{id:string,school_year_id:string,grade_level:int,name:string,archived:bool}|null
     */
    public function find(string $id, bool $lock = false): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT `id`, `school_year_id`, `grade_level`, `name`, `archived_at` FROM `classes` WHERE `id` = :id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : self::row($row);
    }

    /** @throws \PDOException bei doppelter Bezeichnung im Schuljahr (Fehler 1062) */
    public function insert(string $id, ClassInput $input): void
    {
        DB::getInstance()
            ->prepare('INSERT INTO `classes` (`id`, `school_year_id`, `grade_level`, `name`, `name_key`) VALUES (:id, :year, :grade, :name, :key)')
            ->execute(['id' => $id, 'year' => $input->schoolYearId, 'grade' => $input->attributes->gradeLevel, 'name' => $input->attributes->name, 'key' => $input->attributes->nameKey()]);
    }

    /**
     * Aendert Klassenstufe und Bezeichnung; `name_key` wird mitgepflegt. Das Schuljahr bleibt.
     *
     * @throws \PDOException bei doppelter Bezeichnung im Schuljahr (Fehler 1062)
     */
    public function update(string $id, ClassAttributes $attributes): void
    {
        DB::getInstance()
            ->prepare('UPDATE `classes` SET `grade_level` = :grade, `name` = :name, `name_key` = :key WHERE `id` = :id')
            ->execute(['id' => $id, 'grade' => $attributes->gradeLevel, 'name' => $attributes->name, 'key' => $attributes->nameKey()]);
    }

    public function setArchived(string $id, bool $archived): void
    {
        DB::getInstance()
            ->prepare('UPDATE `classes` SET `archived_at` = ' . ($archived ? 'CURRENT_TIMESTAMP(6)' : 'NULL') . ' WHERE `id` = :id')
            ->execute(['id' => $id]);
    }

    public function delete(string $id): void
    {
        DB::getInstance()->prepare('DELETE FROM `classes` WHERE `id` = :id')->execute(['id' => $id]);
    }

    /**
     * @param array<string,mixed> $r
     * @return array{id:string,school_year_id:string,grade_level:int,name:string,archived:bool}
     */
    private static function row(array $r): array
    {
        return ['id' => $r['id'], 'school_year_id' => $r['school_year_id'], 'grade_level' => (int) $r['grade_level'], 'name' => $r['name'], 'archived' => $r['archived_at'] !== null];
    }
}
