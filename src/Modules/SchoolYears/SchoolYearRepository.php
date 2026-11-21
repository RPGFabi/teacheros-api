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

    /**
     * Gibt es ein Schuljahr, das sich mit dem Zeitraum ueberschneidet? Ein gemeinsamer Tag zaehlt als Ueberschneidung.
     * Beim Bearbeiten bleibt das Schuljahr `$exceptId` selbst ausser Betracht.
     */
    public function overlaps(SchoolYearPeriod $period, ?string $exceptId = null): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM `school_years` WHERE `starts_on` <= :ends AND `ends_on` >= :starts AND (:except IS NULL OR `id` <> :except2) LIMIT 1');
        $stmt->execute(['starts' => $period->startsOn, 'ends' => $period->endsOn, 'except' => $exceptId, 'except2' => $exceptId]);
        return $stmt->fetchColumn() !== false;
    }

    public function update(string $id, SchoolYearPeriod $period): void
    {
        DB::getInstance()
            ->prepare('UPDATE `school_years` SET `starts_on` = :starts, `ends_on` = :ends WHERE `id` = :id')
            ->execute(['id' => $id, 'starts' => $period->startsOn, 'ends' => $period->endsOn]);
    }

    /** @throws \PDOException wenn noch Zeilen auf das Schuljahr verweisen (Fremdschluessel, Fehler 1451) */
    public function delete(string $id): void
    {
        DB::getInstance()->prepare('DELETE FROM `school_years` WHERE `id` = :id')->execute(['id' => $id]);
    }

    public function insert(string $id, SchoolYearPeriod $period): void
    {
        DB::getInstance()
            ->prepare('INSERT INTO `school_years` (`id`, `starts_on`, `ends_on`) VALUES (:id, :starts, :ends)')
            ->execute(['id' => $id, 'starts' => $period->startsOn, 'ends' => $period->endsOn]);
    }
}
