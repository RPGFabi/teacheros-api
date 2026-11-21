<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Database\MysqlError;
use TeacherOS\Shared\Database\Transactions;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Uuid;

/**
 * Schuljahre ansehen, anlegen, bearbeiten und loeschen (Tickets #54, #56, GLOSSARY.md: Schuljahr). Die Bezeichnung kommt aus dem Zeitraum
 * ({@see SchoolYearSpan::name()}), der Status aus dem heutigen Datum der Schule ({@see SchoolYearStatus}); beides
 * wird nie gespeichert. Einwaende anderer Module gegen Aendern und Loeschen kommen ueber {@see SchoolYearGuards}.
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
        private readonly SchoolYearGuards $guards = new SchoolYearGuards(),
    ) {
    }

    /**
     * Alle Schuljahre nach Beginn, mit abgeleitetem Status.
     *
     * @return list<SchoolYear>
     */
    public function list(): array
    {
        return $this->present($this->repository->all());
    }

    /**
     * Ein Schuljahr nach Kennung, oder null, wenn es keines mit dieser Kennung gibt.
     *
     * @return SchoolYear|null
     */
    public function find(string $id): ?SchoolYear
    {
        foreach ($this->list() as $year) {
            if ($year->id === $id) {
                return $year;
            }
        }
        return null;
    }

    /**
     * Wie {@see self::find()}, aber ein fehlendes Schuljahr ist `school_year_not_found`.
     *
     * @return SchoolYear
     * @throws CodedException `school_year_not_found` (404)
     */
    public function get(string $id): SchoolYear
    {
        return $this->find($id) ?? throw self::notFound();
    }

    /** Die Ablehnung "Schuljahr gibt es nicht"; auch fuer Module, die Schuljahre referenzieren (Fremdschluessel). */
    public static function notFound(): CodedException
    {
        return new CodedException('school_year_not_found', 404, 'Das Schuljahr gibt es nicht.');
    }

    /**
     * Legt ein Schuljahr an: Ueberschneidungen werden abgelehnt (gleicher Tag zaehlt), Luecken sind erlaubt. Pruefung,
     * Anlegen und Audit-Eintrag `school_year.created` laufen unter einer Sperre in einer Transaktion, damit zwei
     * gleichzeitige Anfragen sich nicht beide durchsetzen.
     *
     * @return SchoolYear
     * @throws CodedException `school_year_overlap` (409), `school_years_busy` (503)
     */
    public function create(Actor $actor, SchoolYearSpan $span): SchoolYear
    {
        $id = Uuid::v4();
        $this->exclusively(function () use ($actor, $span, $id): void {
            $this->assertNoOverlap($span);
            $this->repository->insert($id, $span);
            $this->audit->record($actor, AuditLog::SCHOOL_YEAR_CREATED, 'school_year', $id, ['name' => $span->name()]);
        });

        return $this->find($id) ?? throw new \LogicException('Das angelegte Schuljahr fehlt in der Liste.');
    }

    /**
     * Aendert den Zeitraum eines Schuljahres (Ticket #56) nach denselben Regeln wie beim Anlegen; die Bezeichnung folgt
     * dem neuen Zeitraum. Vorher fragt die Pruefung jeden angemeldeten {@see SchoolYearGuard}. Audit-Eintrag
     * `school_year.updated` mit alter und neuer Bezeichnung.
     *
     * @return SchoolYear
     * @throws CodedException `school_year_not_found` (404), `school_year_overlap` (409), der Code eines Einwands (409), `school_years_busy` (503)
     */
    public function update(Actor $actor, string $id, SchoolYearSpan $span): SchoolYear
    {
        $this->exclusively(function () use ($actor, $id, $span): void {
            $year = $this->get($id);
            $this->guards->assertUpdatable($year, $span);
            $this->assertNoOverlap($span, $id);
            $this->repository->update($id, $span);
            $this->audit->record($actor, AuditLog::SCHOOL_YEAR_UPDATED, 'school_year', $id, ['old_name' => $year->name, 'name' => $span->name()]);
        });

        return $this->get($id);
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
            $year = $this->get($id);
            $this->guards->assertDeletable($year);
            try {
                $this->repository->delete($id);
            } catch (\PDOException $e) {
                // Fremdschluessel: zwischen Pruefung und Loeschen ist eine Zeile eingetroffen, die ein Modul ohne unsere
                // Sperre anlegt (z. B. eine neue Klasse). Dieselbe Sperre wie ein Einwand, nur ohne Modulwissen.
                if (MysqlError::of($e) === MysqlError::ROW_IS_REFERENCED) {
                    throw new CodedException('school_year_in_use', 409, 'Das Schuljahr wird noch verwendet und kann nicht gelöscht werden.');
                }
                throw $e;
            }
            $this->audit->record($actor, AuditLog::SCHOOL_YEAR_DELETED, 'school_year', $id, ['name' => $year->name]);
        });
    }

    /** @throws CodedException `school_year_overlap` (409) */
    private function assertNoOverlap(SchoolYearSpan $span, ?string $exceptId = null): void
    {
        if ($this->repository->overlaps($span, $exceptId)) {
            throw new CodedException('school_year_overlap', 409, 'Der Zeitraum überschneidet sich mit einem bestehenden Schuljahr.');
        }
    }

    /**
     * Fuehrt eine Schreibaktion unter der Sperre der Schuljahre in einer Transaktion aus: Pruefen und Schreiben sehen
     * denselben Stand, zwei gleichzeitige Anfragen kommen nacheinander dran.
     *
     * @throws CodedException `school_years_busy` (503), wenn die Sperre nicht binnen 10 s frei wird
     */
    private function exclusively(\Closure $action): void
    {
        Transactions::underLock(
            self::LOCK,
            self::LOCK_WAIT_SECONDS,
            static fn (): \Throwable => new CodedException('school_years_busy', 503, 'Die Schuljahre werden gerade geändert. Bitte versuchen Sie es gleich noch einmal.'),
            $action,
        );
    }

    /**
     * @param list<array{id:string,starts_on:string,ends_on:string}> $rows nach Beginn
     * @return list<SchoolYear>
     */
    private function present(array $rows): array
    {
        $statuses = SchoolYearStatus::derive(
            array_map(static fn (array $r): array => [$r['starts_on'], $r['ends_on']], $rows),
            $this->today ?? new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE)),
        );
        $years = [];
        foreach ($rows as $i => $row) {
            $years[] = new SchoolYear($row['id'], SchoolYearSpan::fromStrings($row['starts_on'], $row['ends_on'])->name(), $row['starts_on'], $row['ends_on'], $statuses[$i]);
        }
        return $years;
    }
}
