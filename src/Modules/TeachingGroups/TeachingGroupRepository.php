<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use Hoelzle\Core\Database\DB;

/** Liest und schreibt die Tabellen `teaching_groups` und `teaching_group_classes`. Regeln (Eingabe, Auswahl, Sortierung) liegen nicht hier. */
final class TeachingGroupRepository
{
    /**
     * Die Fachgruppen eines Schuljahres, unsortiert.
     *
     * @return list<array{id:string,school_year_id:string,subject_id:string,name:string}>
     */
    public function inSchoolYear(string $schoolYearId): array
    {
        $stmt = DB::getInstance()->prepare('SELECT `id`, `school_year_id`, `subject_id`, `name` FROM `teaching_groups` WHERE `school_year_id` = :year');
        $stmt->execute(['year' => $schoolYearId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Eine Fachgruppe; mit $lock innerhalb einer Transaktion fuer die Dauer der Aenderung gesperrt.
     *
     * @return array{id:string,school_year_id:string,subject_id:string,name:string}|null
     */
    public function find(string $id, bool $lock = false): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT `id`, `school_year_id`, `subject_id`, `name` FROM `teaching_groups` WHERE `id` = :id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
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
            ->execute(['id' => $id, 'year' => $input->schoolYearId, 'subject' => $input->subjectId, 'name' => $input->name, 'key' => $input->nameKey()]);
        $link = $db->prepare('INSERT INTO `teaching_group_classes` (`teaching_group_id`, `class_id`) VALUES (:id, :class)');
        foreach ($input->classIds as $classId) {
            $link->execute(['id' => $id, 'class' => $classId]);
        }
    }
}
