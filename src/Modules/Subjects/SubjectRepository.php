<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use Hoelzle\Core\Database\DB;

/** Liest und schreibt die Tabelle `subjects`. Regeln (Eingabe, Sortierung) liegen nicht hier. */
final class SubjectRepository
{
    /** @return list<Subject> unsortiert */
    public function all(): array
    {
        $rows = DB::getInstance()->query('SELECT `id`, `code`, `name` FROM `subjects`')->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): Subject => new Subject($r['id'], $r['code'], $r['name']), $rows);
    }

    /** @throws \PDOException bei doppeltem Kuerzel (Fehler 1062) */
    public function insert(string $id, SubjectInput $input): void
    {
        DB::getInstance()
            ->prepare('INSERT INTO `subjects` (`id`, `code`, `code_key`, `name`) VALUES (:id, :code, :key, :name)')
            ->execute(['id' => $id, 'code' => $input->code, 'key' => $input->codeKey(), 'name' => $input->name]);
    }
}
