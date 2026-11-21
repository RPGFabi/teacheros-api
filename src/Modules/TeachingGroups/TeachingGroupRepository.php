<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use Hoelzle\Core\Database\DB;

/** Liest und schreibt die Tabellen `teaching_groups` und `teaching_group_classes`. Regeln (Eingabe, Auswahl, Archiv, Sortierung) liegen nicht hier. */
final class TeachingGroupRepository
{
    /**
     * Die Fachgruppen eines Schuljahres, unsortiert; archivierte nur auf Wunsch.
     *
     * @return list<array{id:string,school_year_id:string,subject_id:string,name:string,archived:bool}>
     */
    public function inSchoolYear(string $schoolYearId, bool $includeArchived = false): array
    {
        $sql = 'SELECT `id`, `school_year_id`, `subject_id`, `name`, `archived_at` FROM `teaching_groups` WHERE `school_year_id` = :year'
            . ($includeArchived ? '' : ' AND `archived_at` IS NULL');
        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute(['year' => $schoolYearId]);
        return array_map(self::row(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Eine Fachgruppe; mit $lock innerhalb einer Transaktion fuer die Dauer der Aenderung gesperrt.
     *
     * @return array{id:string,school_year_id:string,subject_id:string,name:string,archived:bool}|null
     */
    public function find(string $id, bool $lock = false): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT `id`, `school_year_id`, `subject_id`, `name`, `archived_at` FROM `teaching_groups` WHERE `id` = :id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : self::row($row);
    }

    /**
     * Die Klassen-Kennungen der Fachgruppen eines Schuljahres, je Fachgruppe (unsortiert).
     *
     * @return array<string,list<string>> Kennung der Fachgruppe => Kennungen ihrer Klassen
     */
    public function classIdsInSchoolYear(string $schoolYearId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT gc.`teaching_group_id`, gc.`class_id` FROM `teaching_group_classes` gc
             JOIN `teaching_groups` g ON g.`id` = gc.`teaching_group_id` WHERE g.`school_year_id` = :year'
        );
        $stmt->execute(['year' => $schoolYearId]);
        $byGroup = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byGroup[$row['teaching_group_id']][] = $row['class_id'];
        }
        return $byGroup;
    }

    /**
     * Die Klassen-Kennungen einer Fachgruppe (unsortiert).
     *
     * @return list<string>
     */
    public function classIdsOf(string $id): array
    {
        $stmt = DB::getInstance()->prepare('SELECT `class_id` FROM `teaching_group_classes` WHERE `teaching_group_id` = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Legt die Fachgruppe mit ihren Klassen an.
     *
     * @throws \PDOException bei doppelter Bezeichnung im Schuljahr (Fehler 1062) oder fehlendem Schuljahr, Fach oder fehlender Klasse (Fehler 1452)
     */
    public function insert(string $id, TeachingGroupInput $input): void
    {
        $db = DB::getInstance();
        $db->prepare('INSERT INTO `teaching_groups` (`id`, `school_year_id`, `subject_id`, `name`, `name_key`) VALUES (:id, :year, :subject, :name, :key)')
            ->execute(['id' => $id, 'year' => $input->schoolYearId, 'subject' => $input->subjectId, 'name' => $input->attributes->name, 'key' => $input->attributes->nameKey()]);
        $link = $db->prepare('INSERT INTO `teaching_group_classes` (`teaching_group_id`, `class_id`) VALUES (:id, :class)');
        foreach ($input->attributes->classIds as $classId) {
            $link->execute(['id' => $id, 'class' => $classId]);
        }
    }

    /**
     * Aendert Bezeichnung (samt `name_key`) und Klassen: legt die neuen Klassen an und entfernt die nicht mehr genannten. Schuljahr
     * und Fach bleiben.
     *
     * @param list<string> $addClassIds
     * @param list<string> $removeClassIds
     * @throws \PDOException bei doppelter Bezeichnung im Schuljahr (Fehler 1062) oder fehlender Klasse (Fehler 1452)
     */
    public function update(string $id, TeachingGroupAttributes $attributes, array $addClassIds, array $removeClassIds): void
    {
        $db = DB::getInstance();
        $db->prepare('UPDATE `teaching_groups` SET `name` = :name, `name_key` = :key WHERE `id` = :id')
            ->execute(['id' => $id, 'name' => $attributes->name, 'key' => $attributes->nameKey()]);
        $add = $db->prepare('INSERT INTO `teaching_group_classes` (`teaching_group_id`, `class_id`) VALUES (:id, :class)');
        foreach ($addClassIds as $classId) {
            $add->execute(['id' => $id, 'class' => $classId]);
        }
        $remove = $db->prepare('DELETE FROM `teaching_group_classes` WHERE `teaching_group_id` = :id AND `class_id` = :class');
        foreach ($removeClassIds as $classId) {
            $remove->execute(['id' => $id, 'class' => $classId]);
        }
    }

    public function setArchived(string $id, bool $archived): void
    {
        DB::getInstance()
            ->prepare('UPDATE `teaching_groups` SET `archived_at` = ' . ($archived ? 'CURRENT_TIMESTAMP(6)' : 'NULL') . ' WHERE `id` = :id')
            ->execute(['id' => $id]);
    }

    /** Loescht die Fachgruppe; ihre Klassen-Verknuepfungen entfaellt die Datenbank mit (ON DELETE CASCADE). */
    public function delete(string $id): void
    {
        DB::getInstance()->prepare('DELETE FROM `teaching_groups` WHERE `id` = :id')->execute(['id' => $id]);
    }

    /**
     * @param array<string,mixed> $r
     * @return array{id:string,school_year_id:string,subject_id:string,name:string,archived:bool}
     */
    private static function row(array $r): array
    {
        return ['id' => $r['id'], 'school_year_id' => $r['school_year_id'], 'subject_id' => $r['subject_id'], 'name' => $r['name'], 'archived' => $r['archived_at'] !== null];
    }
}
