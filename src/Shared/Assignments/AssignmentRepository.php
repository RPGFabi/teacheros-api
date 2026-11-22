<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Assignments;

use Hoelzle\Core\Database\DB;

/**
 * Liest und schreibt die Tabelle der Zuordnungen einer {@see AssignmentKind} (`teacher_assignments`, `class_leaders`) und
 * sperrt das Elternobjekt (Fachgruppe, Klasse) und das Schuljahr. Regeln (Gueltigkeitszeitraum, Ueberschneidung, Obergrenze)
 * liegen in {@see AssignmentRules}. Die Zeilen tragen das Elternobjekt immer als `parent_id`, gleich fuer jede Art.
 */
final class AssignmentRepository
{
    public function __construct(private readonly AssignmentKind $kind)
    {
    }

    /**
     * Die Zuordnungen eines Elternobjekts, nach Beginn und Kennung.
     *
     * @return list<array{id:string,parent_id:string,account_id:string,valid_from:string,valid_until:?string}>
     */
    public function forParent(string $parentId): array
    {
        $stmt = DB::getInstance()->prepare('SELECT ' . $this->columns() . ' FROM `' . $this->kind->table . '` WHERE `' . $this->kind->parentColumn . '` = :parent ORDER BY `valid_from`, `id`');
        $stmt->execute(['parent' => $parentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Eine Zuordnung; mit $lock innerhalb einer Transaktion fuer die Dauer der Aenderung gesperrt.
     *
     * @return array{id:string,parent_id:string,account_id:string,valid_from:string,valid_until:?string}|null
     */
    public function find(string $id, bool $lock = false): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT ' . $this->columns() . ' FROM `' . $this->kind->table . '` WHERE `id` = :id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @throws \PDOException wenn es das Elternobjekt nicht (mehr) gibt (Fremdschluessel, Fehler 1452) */
    public function insert(string $id, string $parentId, string $accountId, Validity $validity): void
    {
        DB::getInstance()->prepare(
            'INSERT INTO `' . $this->kind->table . '` (`id`, `' . $this->kind->parentColumn . '`, `account_id`, `valid_from`, `valid_until`) VALUES (:id, :parent, :account, :from, :until)'
        )->execute(['id' => $id, 'parent' => $parentId, 'account' => $accountId, 'from' => $validity->validFrom, 'until' => $validity->validUntil]);
    }

    /** Setzt die Obergrenze (exklusiv); der Beginn bleibt. */
    public function updateUntil(string $id, string $validUntil): void
    {
        DB::getInstance()->prepare('UPDATE `' . $this->kind->table . '` SET `valid_until` = :until WHERE `id` = :id')->execute(['id' => $id, 'until' => $validUntil]);
    }

    public function delete(string $id): void
    {
        DB::getInstance()->prepare('DELETE FROM `' . $this->kind->table . '` WHERE `id` = :id')->execute(['id' => $id]);
    }

    /**
     * Das Schuljahr (Kennung) eines Elternobjekts oder null, wenn es das Elternobjekt nicht gibt; mit $lock fuer die Dauer der
     * Transaktion gesperrt (Anlegen von Zuordnungen desselben Elternobjekts laeuft nacheinander, ebenso das Archivieren).
     *
     * @return array{school_year_id:string,archived:bool}|null
     */
    public function parent(string $parentId, bool $lock = false): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT `school_year_id`, `archived_at` FROM `' . $this->kind->parentTable . '` WHERE `id` = :id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $parentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : ['school_year_id' => (string) $row['school_year_id'], 'archived' => $row['archived_at'] !== null];
    }

    /** Haengt am Elternobjekt mindestens eine Zuordnung (auch eine beendete)? */
    public function parentHasAssignments(string $parentId): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM `' . $this->kind->table . '` WHERE `' . $this->kind->parentColumn . '` = :parent LIMIT 1');
        $stmt->execute(['parent' => $parentId]);
        return $stmt->fetchColumn() !== false;
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
     * Fiele eine Zuordnung in Elternobjekten dieses Schuljahres heraus, wenn das Schuljahr vom ersten bis zum letzten Tag
     * (einschliesslich) liefe? Herausfallen heisst: Beginn ausserhalb oder ein festes Ende nach dem letzten Tag. Ein offenes
     * Ende folgt dem Schuljahresende und faellt nur heraus, wenn sein Beginn ausserhalb liegt. Aktuelle Lesung (sperrend), damit
     * auch eben erst eingetragene Zuordnungen zaehlen.
     */
    public function anyOutsideSchoolYear(string $schoolYearId, string $startsOn, string $endsOn): bool
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT 1 FROM `' . $this->kind->table . '` a JOIN `' . $this->kind->parentTable . '` p ON p.`id` = a.`' . $this->kind->parentColumn . '`
             WHERE p.`school_year_id` = :year
               AND (a.`valid_from` < :starts OR a.`valid_from` > :ends OR (a.`valid_until` IS NOT NULL AND a.`valid_until` > :limit))
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['year' => $schoolYearId, 'starts' => $startsOn, 'ends' => $endsOn, 'limit' => Validity::nextDay($endsOn)]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Gibt es in Elternobjekten dieses Schuljahres eine offene Zuordnung (ohne festes Ende), die am Tag $today oder davor schon
     * begonnen hat? Sie gilt bis zum Schuljahresende, ein frueheres Schuljahresende nahme ihr schon gegoltene Tage. Aktuelle
     * Lesung (sperrend).
     */
    public function anyOpenStartedBy(string $schoolYearId, string $today): bool
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT 1 FROM `' . $this->kind->table . '` a JOIN `' . $this->kind->parentTable . '` p ON p.`id` = a.`' . $this->kind->parentColumn . '`
             WHERE p.`school_year_id` = :year AND a.`valid_until` IS NULL AND a.`valid_from` <= :today
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['year' => $schoolYearId, 'today' => $today]);
        return $stmt->fetchColumn() !== false;
    }

    private function columns(): string
    {
        return '`id`, `' . $this->kind->parentColumn . '` AS `parent_id`, `account_id`, `valid_from`, `valid_until`';
    }
}
