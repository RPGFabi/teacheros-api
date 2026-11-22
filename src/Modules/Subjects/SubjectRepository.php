<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use Hoelzle\Core\Database\DB;

/** Liest und schreibt die Tabelle `subjects`. Regeln (Eingabe, Sortierung) liegen nicht hier. */
final class SubjectRepository
{
    /**
     * Die Faecher, unsortiert; archivierte nur auf Wunsch.
     *
     * @return list<Subject>
     */
    public function all(bool $includeArchived = false): array
    {
        $sql = 'SELECT `id`, `code`, `name`, `archived_at` FROM `subjects`' . ($includeArchived ? '' : ' WHERE `archived_at` IS NULL');
        $rows = DB::getInstance()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(self::subject(...), $rows);
    }

    /** Ein Fach; mit $lock innerhalb einer Transaktion fuer die Dauer der Aenderung gesperrt. */
    public function find(string $id, bool $lock = false): ?Subject
    {
        $stmt = DB::getInstance()->prepare('SELECT `id`, `code`, `name`, `archived_at` FROM `subjects` WHERE `id` = :id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : self::subject($row);
    }

    /**
     * Ein waehlbares, also nicht archiviertes Fach, fuer die Dauer der laufenden Transaktion gegen Aenderungen gesperrt
     * (gemeinsame Sperre): ein gleichzeitiges Archivieren oder Loeschen wartet, bis die Transaktion endet, und eines, das
     * schon laeuft, ist vorher durch. Gelesen wird der aktuelle Stand.
     */
    public function findSelectableShared(string $id): ?Subject
    {
        $stmt = DB::getInstance()->prepare('SELECT `id`, `code`, `name`, `archived_at` FROM `subjects` WHERE `id` = :id AND `archived_at` IS NULL LOCK IN SHARE MODE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : self::subject($row);
    }

    /** @throws \PDOException bei doppeltem Kuerzel (Fehler 1062) */
    public function insert(string $id, SubjectInput $input): void
    {
        DB::getInstance()
            ->prepare('INSERT INTO `subjects` (`id`, `code`, `code_key`, `name`) VALUES (:id, :code, :key, :name)')
            ->execute(['id' => $id, 'code' => $input->code, 'key' => $input->codeKey(), 'name' => $input->name]);
    }

    /** Aendert die Bezeichnung; das Kuerzel (und sein Schluessel) bleibt. */
    public function rename(string $id, string $name): void
    {
        DB::getInstance()->prepare('UPDATE `subjects` SET `name` = :name WHERE `id` = :id')->execute(['id' => $id, 'name' => $name]);
    }

    public function setArchived(string $id, bool $archived): void
    {
        DB::getInstance()
            ->prepare('UPDATE `subjects` SET `archived_at` = ' . ($archived ? 'CURRENT_TIMESTAMP(6)' : 'NULL') . ' WHERE `id` = :id')
            ->execute(['id' => $id]);
    }

    public function delete(string $id): void
    {
        DB::getInstance()->prepare('DELETE FROM `subjects` WHERE `id` = :id')->execute(['id' => $id]);
    }

    /** @param array<string,mixed> $r */
    private static function subject(array $r): Subject
    {
        return new Subject($r['id'], $r['code'], $r['name'], $r['archived_at'] !== null);
    }
}
