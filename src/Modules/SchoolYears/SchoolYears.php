<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Uuid;

/**
 * Schuljahre ansehen, anlegen, bearbeiten und loeschen (Tickets #54, #56, GLOSSARY.md: Schuljahr). Die Bezeichnung kommt aus dem Zeitraum
 * ({@see SchoolYearPeriod::name()}), der Status aus dem heutigen Datum der Schule ({@see SchoolYearStatus}); beides
 * wird nie gespeichert.
 */
final class SchoolYears
{
    /** Die Schule rechnet in ihrer Ortszeit: "heute" ist der Kalendertag in Deutschland. */
    private const TIMEZONE = 'Europe/Berlin';
    private const LOCK = 'teacheros.school_years';
    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private readonly SchoolYearRepository $repository = new SchoolYearRepository(),
        private readonly AuditLog $audit = new AuditLog(),
        private readonly ?\DateTimeImmutable $today = null,
    ) {
    }

    /**
     * Alle Schuljahre nach Beginn, mit abgeleitetem Status.
     *
     * @return list<array{id:string,name:string,starts_on:string,ends_on:string,status:string}>
     */
    public function list(): array
    {
        return $this->present($this->repository->all());
    }

    /**
     * Ein Schuljahr nach Kennung, oder null, wenn es keines mit dieser Kennung gibt.
     *
     * @return array{id:string,name:string,starts_on:string,ends_on:string,status:string}|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->list() as $year) {
            if ($year['id'] === $id) {
                return $year;
            }
        }
        return null;
    }

    /**
     * Legt ein Schuljahr an: Ueberschneidungen werden abgelehnt (gleicher Tag zaehlt), Luecken sind erlaubt. Pruefung,
     * Anlegen und Audit-Eintrag `school_year.created` laufen unter einer Sperre in einer Transaktion, damit zwei
     * gleichzeitige Anfragen sich nicht beide durchsetzen.
     *
     * @return array{id:string,name:string,starts_on:string,ends_on:string,status:string}
     * @throws CodedException `school_year_overlap` (409), `school_years_busy` (503)
     */
    public function create(Actor $actor, SchoolYearPeriod $period): array
    {
        $id = Uuid::v4();
        $this->exclusively(function () use ($actor, $period, $id): void {
            if ($this->repository->overlaps($period)) {
                throw new CodedException('school_year_overlap', 409, 'Der Zeitraum überschneidet sich mit einem bestehenden Schuljahr.');
            }
            $this->repository->insert($id, $period);
            $this->audit->record($actor, AuditLog::SCHOOL_YEAR_CREATED, 'school_year', $id, ['name' => $period->name()]);
        });

        return $this->find($id) ?? throw new \LogicException('Das angelegte Schuljahr fehlt in der Liste.');
    }

    /**
     * Aendert den Zeitraum eines Schuljahres (Ticket #56) nach denselben Regeln wie beim Anlegen; die Bezeichnung folgt
     * dem neuen Zeitraum. Vorher fragt die Pruefung jedes angemeldeten {@see SchoolYearGuard}. Audit-Eintrag
     * `school_year.updated` mit alter und neuer Bezeichnung.
     *
     * @return array{id:string,name:string,starts_on:string,ends_on:string,status:string}
     * @throws CodedException `school_year_not_found` (404), `school_year_overlap` (409), der Code eines Einwands (409), `school_years_busy` (503)
     */
    public function update(Actor $actor, string $id, SchoolYearPeriod $period): array
    {
        $this->exclusively(function () use ($actor, $id, $period): void {
            $year = $this->find($id) ?? throw self::notFound();
            foreach (SchoolYearGuards::all() as $guard) {
                $objection = $guard->objectionToUpdate($year, $period);
                if ($objection !== null) {
                    throw new CodedException($objection->code, 409, $objection->message);
                }
            }
            if ($this->repository->overlaps($period, $id)) {
                throw new CodedException('school_year_overlap', 409, 'Der Zeitraum überschneidet sich mit einem bestehenden Schuljahr.');
            }
            $this->repository->update($id, $period);
            $this->audit->record($actor, AuditLog::SCHOOL_YEAR_UPDATED, 'school_year', $id, ['old_name' => $year['name'], 'name' => $period->name()]);
        });

        return $this->find($id) ?? throw new \LogicException('Das bearbeitete Schuljahr fehlt in der Liste.');
    }

    /**
     * Loescht ein Schuljahr (Ticket #56), sofern kein angemeldeter {@see SchoolYearGuard} etwas einzuwenden hat (die
     * Klassen: ein Schuljahr mit Klassen bleibt bestehen). Audit-Eintrag `school_year.deleted`.
     *
     * @throws CodedException `school_year_not_found` (404), der Code eines Einwands (409), `school_year_in_use` (409), `school_years_busy` (503)
     */
    public function delete(Actor $actor, string $id): void
    {
        $this->exclusively(function () use ($actor, $id): void {
            $year = $this->find($id) ?? throw self::notFound();
            foreach (SchoolYearGuards::all() as $guard) {
                $objection = $guard->objectionToDeletion($year);
                if ($objection !== null) {
                    throw new CodedException($objection->code, 409, $objection->message);
                }
            }
            try {
                $this->repository->delete($id);
            } catch (\PDOException $e) {
                // Fremdschluessel (1451): zwischen Pruefung und Loeschen ist eine Zeile eingetroffen, die ein Modul ohne
                // unsere Sperre anlegt (z. B. eine neue Klasse). Dieselbe Sperre wie ein Einwand, nur ohne Modulwissen.
                if (($e->errorInfo[1] ?? null) === 1451) {
                    throw new CodedException('school_year_in_use', 409, 'Das Schuljahr wird noch verwendet und kann nicht gelöscht werden.');
                }
                throw $e;
            }
            $this->audit->record($actor, AuditLog::SCHOOL_YEAR_DELETED, 'school_year', $id, ['name' => $year['name']]);
        });
    }

    private static function notFound(): CodedException
    {
        return new CodedException('school_year_not_found', 404, 'Das Schuljahr gibt es nicht.');
    }

    /**
     * Fuehrt eine Schreibaktion unter der Sperre der Schuljahre in einer Transaktion aus: Pruefen und Schreiben sehen
     * denselben Stand, zwei gleichzeitige Anfragen kommen nacheinander dran.
     *
     * @throws CodedException `school_years_busy` (503), wenn die Sperre nicht binnen 10 s frei wird
     */
    private function exclusively(callable $action): void
    {
        $pdo = DB::getInstance();
        $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute([self::LOCK, self::LOCK_WAIT_SECONDS]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new CodedException('school_years_busy', 503, 'Die Schuljahre werden gerade geändert. Bitte versuchen Sie es gleich noch einmal.');
        }
        try {
            $pdo->beginTransaction();
            try {
                $action();
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK]);
        }
    }

    /**
     * @param list<array{id:string,starts_on:string,ends_on:string}> $rows nach Beginn
     * @return list<array{id:string,name:string,starts_on:string,ends_on:string,status:string}>
     */
    private function present(array $rows): array
    {
        $statuses = SchoolYearStatus::derive(
            array_map(static fn (array $r): array => [$r['starts_on'], $r['ends_on']], $rows),
            $this->today ?? new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE)),
        );
        $years = [];
        foreach ($rows as $i => $row) {
            $years[] = [
                'id' => $row['id'],
                'name' => SchoolYearPeriod::fromStrings($row['starts_on'], $row['ends_on'])->name(),
                'starts_on' => $row['starts_on'],
                'ends_on' => $row['ends_on'],
                'status' => $statuses[$i]->value,
            ];
        }
        return $years;
    }
}
