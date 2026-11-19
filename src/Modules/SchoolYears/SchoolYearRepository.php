<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

use Hoelzle\Core\Database\DB;

/** Liest und schreibt die Tabelle `school_years`. Regeln (Zeitraum, Ueberschneidung, Status) liegen nicht hier. */
final class SchoolYearRepository
{
    /**
     * Alle Schuljahre, nach Beginn aufsteigend.
     *
     * @return list<array{id:string,starts_on:string,ends_on:string}>
     */
    public function all(): array
    {
        return DB::getInstance()
            ->query('SELECT `id`, `starts_on`, `ends_on` FROM `school_years` ORDER BY `starts_on`')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Gibt es ein Schuljahr, das sich mit dem Zeitraum ueberschneidet? Ein gemeinsamer Tag zaehlt als Ueberschneidung. */
    public function overlaps(SchoolYearPeriod $period): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM `school_years` WHERE `starts_on` <= :ends AND `ends_on` >= :starts LIMIT 1');
        $stmt->execute(['starts' => $period->startsOn, 'ends' => $period->endsOn]);
        return $stmt->fetchColumn() !== false;
    }

    public function insert(string $id, SchoolYearPeriod $period): void
    {
        DB::getInstance()
            ->prepare('INSERT INTO `school_years` (`id`, `starts_on`, `ends_on`) VALUES (:id, :starts, :ends)')
            ->execute(['id' => $id, 'starts' => $period->startsOn, 'ends' => $period->endsOn]);
    }
}
