<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Schuljahre ansehen und anlegen (Ticket #54, GLOSSARY.md: Schuljahr). Die Bezeichnung kommt aus dem Zeitraum
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
     * Legt ein Schuljahr an: Ueberschneidungen werden abgelehnt (gleicher Tag zaehlt), Luecken sind erlaubt. Pruefung,
     * Anlegen und Audit-Eintrag `school_year.created` laufen unter einer Sperre in einer Transaktion, damit zwei
     * gleichzeitige Anfragen sich nicht beide durchsetzen.
     *
     * @return array{id:string,name:string,starts_on:string,ends_on:string,status:string}
     * @throws CodedException `school_year_overlap` (409), `school_years_busy` (503)
     */
    public function create(Actor $actor, SchoolYearPeriod $period): array
    {
        $pdo = DB::getInstance();
        $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute([self::LOCK, self::LOCK_WAIT_SECONDS]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new CodedException('school_years_busy', 503, 'Die Schuljahre werden gerade geändert. Bitte versuchen Sie es gleich noch einmal.');
        }
        try {
            $id = self::newId();
            $pdo->beginTransaction();
            try {
                if ($this->repository->overlaps($period)) {
                    throw new CodedException('school_year_overlap', 409, 'Der Zeitraum überschneidet sich mit einem bestehenden Schuljahr.');
                }
                $this->repository->insert($id, $period);
                $this->audit->record($actor, AuditLog::SCHOOL_YEAR_CREATED, 'school_year', $id, ['name' => $period->name()]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK]);
        }

        foreach ($this->list() as $year) {
            if ($year['id'] === $id) {
                return $year;
            }
        }
        throw new \LogicException('Das angelegte Schuljahr fehlt in der Liste.');
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

    private static function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
