<?php

declare(strict_types=1);

namespace TeacherOS\Modules\ClassLeaders;

use Hoelzle\Core\Database\DB;
use TeacherOS\Modules\TeacherAssignments\AssignmentPeriod;

/** Liest und schreibt die Tabelle `class_leaders`. Regeln (Zeitraum, Ueberschneidung, Obergrenze) liegen in `AssignmentRules`. */
final class ClassLeaderRepository
{
    private const COLUMNS = '`id`, `class_id`, `account_id`, `valid_from`, `valid_until`';

    /**
     * Die Klassenleitungen einer Klasse, nach Beginn und Kennung.
     *
     * @return list<array{id:string,class_id:string,account_id:string,valid_from:string,valid_until:?string}>
     */
    public function forClass(string $classId): array
    {
        $stmt = DB::getInstance()->prepare('SELECT ' . self::COLUMNS . ' FROM `class_leaders` WHERE `class_id` = :class ORDER BY `valid_from`, `id`');
        $stmt->execute(['class' => $classId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Eine Klassenleitung; mit $lock innerhalb einer Transaktion fuer die Dauer der Aenderung gesperrt.
     *
     * @return array{id:string,class_id:string,account_id:string,valid_from:string,valid_until:?string}|null
     */
    public function find(string $id, bool $lock = false): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT ' . self::COLUMNS . ' FROM `class_leaders` WHERE `id` = :id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @throws \PDOException wenn es die Klasse nicht (mehr) gibt (Fremdschluessel, Fehler 1452) */
    public function insert(string $id, string $classId, string $accountId, AssignmentPeriod $period): void
    {
        DB::getInstance()->prepare(
            'INSERT INTO `class_leaders` (`id`, `class_id`, `account_id`, `valid_from`, `valid_until`) VALUES (:id, :class, :account, :from, :until)'
        )->execute(['id' => $id, 'class' => $classId, 'account' => $accountId, 'from' => $period->validFrom, 'until' => $period->validUntil]);
    }

    /** Setzt die Obergrenze (exklusiv); der Beginn bleibt. */
    public function updateUntil(string $id, string $validUntil): void
    {
        DB::getInstance()->prepare('UPDATE `class_leaders` SET `valid_until` = :until WHERE `id` = :id')->execute(['id' => $id, 'until' => $validUntil]);
    }

    public function delete(string $id): void
    {
        DB::getInstance()->prepare('DELETE FROM `class_leaders` WHERE `id` = :id')->execute(['id' => $id]);
    }

    /** Haengt an der Klasse mindestens eine Klassenleitung (auch eine beendete)? */
    public function classHasLeaders(string $classId): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM `class_leaders` WHERE `class_id` = :class LIMIT 1');
        $stmt->execute(['class' => $classId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Sperrt die Zeile des Schuljahres: gemeinsam ($exclusive false, Anlegen einer Klassenleitung) oder exklusiv ($exclusive
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
     * Fiele eine Klassenleitung in Klassen dieses Schuljahres heraus, wenn das Schuljahr vom ersten bis zum letzten Tag
     * (einschliesslich) liefe? Herausfallen heisst: Beginn ausserhalb oder ein festes Ende nach dem letzten Tag. Ein offenes
     * Ende folgt dem Schuljahresende. Aktuelle Lesung (sperrend), damit auch eben erst eingetragene Klassenleitungen zaehlen.
     */
    public function anyOutsideSchoolYear(string $schoolYearId, string $startsOn, string $endsOn): bool
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT 1 FROM `class_leaders` cl JOIN `classes` c ON c.`id` = cl.`class_id`
             WHERE c.`school_year_id` = :year
               AND (cl.`valid_from` < :starts OR cl.`valid_from` > :ends OR (cl.`valid_until` IS NOT NULL AND cl.`valid_until` > :limit))
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['year' => $schoolYearId, 'starts' => $startsOn, 'ends' => $endsOn, 'limit' => AssignmentPeriod::nextDay($endsOn)]);
        return $stmt->fetchColumn() !== false;
    }
}
