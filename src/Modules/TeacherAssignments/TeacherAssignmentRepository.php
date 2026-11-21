<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use Hoelzle\Core\Database\DB;

/** Liest und schreibt die Tabelle `teacher_assignments`. Regeln (Zeitraum, Ueberschneidung, Obergrenze) liegen in {@see AssignmentRules}. */
final class TeacherAssignmentRepository
{
    private const COLUMNS = '`id`, `teaching_group_id`, `account_id`, `valid_from`, `valid_until`';

    /**
     * Die Zuordnungen einer Fachgruppe, nach Beginn und Kennung.
     *
     * @return list<array{id:string,teaching_group_id:string,account_id:string,valid_from:string,valid_until:?string}>
     */
    public function forGroup(string $teachingGroupId): array
    {
        $stmt = DB::getInstance()->prepare('SELECT ' . self::COLUMNS . ' FROM `teacher_assignments` WHERE `teaching_group_id` = :group ORDER BY `valid_from`, `id`');
        $stmt->execute(['group' => $teachingGroupId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Eine Zuordnung; mit $lock innerhalb einer Transaktion fuer die Dauer der Aenderung gesperrt.
     *
     * @return array{id:string,teaching_group_id:string,account_id:string,valid_from:string,valid_until:?string}|null
     */
    public function find(string $id, bool $lock = false): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT ' . self::COLUMNS . ' FROM `teacher_assignments` WHERE `id` = :id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @throws \PDOException wenn es die Fachgruppe nicht (mehr) gibt (Fremdschluessel, Fehler 1452) */
    public function insert(string $id, string $teachingGroupId, string $accountId, AssignmentPeriod $period): void
    {
        DB::getInstance()->prepare(
            'INSERT INTO `teacher_assignments` (`id`, `teaching_group_id`, `account_id`, `valid_from`, `valid_until`) VALUES (:id, :group, :account, :from, :until)'
        )->execute(['id' => $id, 'group' => $teachingGroupId, 'account' => $accountId, 'from' => $period->validFrom, 'until' => $period->validUntil]);
    }

    /** Setzt die Obergrenze (exklusiv); der Beginn bleibt. */
    public function updateUntil(string $id, string $validUntil): void
    {
        DB::getInstance()->prepare('UPDATE `teacher_assignments` SET `valid_until` = :until WHERE `id` = :id')->execute(['id' => $id, 'until' => $validUntil]);
    }

    public function delete(string $id): void
    {
        DB::getInstance()->prepare('DELETE FROM `teacher_assignments` WHERE `id` = :id')->execute(['id' => $id]);
    }

    /**
     * Sperrt die Fachgruppe fuer die Dauer der Transaktion (Anlegen von Zuordnungen derselben Fachgruppe laeuft nacheinander)
     * und liefert die Kennung ihres Schuljahres, oder null, wenn es die Fachgruppe nicht gibt.
     */
    public function lockGroup(string $teachingGroupId): ?string
    {
        $stmt = DB::getInstance()->prepare('SELECT `school_year_id` FROM `teaching_groups` WHERE `id` = :id FOR UPDATE');
        $stmt->execute(['id' => $teachingGroupId]);
        $yearId = $stmt->fetchColumn();
        return $yearId === false ? null : (string) $yearId;
    }

    /**
     * Sperrt die Zeile des Schuljahres: gemeinsam ($exclusive false, Anlegen einer Zuordnung) oder exklusiv ($exclusive
     * true, Pruefung vor dem Aendern des Schuljahres). So sehen sich beide: wer zuerst kommt, wird vom anderen abgewartet
     * und dessen Pruefung sieht danach den neuen Stand.
     */
    public function lockSchoolYear(string $schoolYearId, bool $exclusive): void
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM `school_years` WHERE `id` = :id' . ($exclusive ? ' FOR UPDATE' : ' LOCK IN SHARE MODE'));
        $stmt->execute(['id' => $schoolYearId]);
        $stmt->fetchColumn();
    }

    /**
     * Fiele eine Zuordnung in Fachgruppen dieses Schuljahres heraus, wenn das Schuljahr vom ersten bis zum letzten Tag
     * (einschliesslich) liefe? Herausfallen heisst: Beginn ausserhalb oder ein festes Ende nach dem letzten Tag. Ein offenes
     * Ende folgt dem Schuljahresende. Aktuelle Lesung (sperrend), damit auch eben erst eingetragene Zuordnungen zaehlen.
     */
    public function anyOutsideSchoolYear(string $schoolYearId, string $startsOn, string $endsOn): bool
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT 1 FROM `teacher_assignments` ta JOIN `teaching_groups` g ON g.`id` = ta.`teaching_group_id`
             WHERE g.`school_year_id` = :year
               AND (ta.`valid_from` < :starts OR ta.`valid_from` > :ends OR (ta.`valid_until` IS NOT NULL AND ta.`valid_until` > :limit))
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['year' => $schoolYearId, 'starts' => $startsOn, 'ends' => $endsOn, 'limit' => AssignmentPeriod::nextDay($endsOn)]);
        return $stmt->fetchColumn() !== false;
    }
}
