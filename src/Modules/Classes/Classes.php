<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use TeacherOS\Modules\SchoolYears\SchoolYears;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Database\MysqlError;
use TeacherOS\Shared\Database\Transactions;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Uuid;

/**
 * Klassen ansehen, anlegen (Ticket #55), bearbeiten, archivieren, wiederherstellen und loeschen (Ticket #57,
 * GLOSSARY.md: Klasse, Archivierung). Jede Klasse gehoert unveraenderlich zu einem Schuljahr und traegt dessen Kennung und
 * Bezeichnung mit. Schuljahre und ihr Status kommen aus {@see SchoolYears}, nicht von hier. Archivierte Klassen bleiben
 * lesbar und ihre Bezeichnung belegt, sind aber schreibgeschuetzt und nicht waehlbar. Geloescht wird nur, was nirgends
 * verknuepft ist; die Verknuepfungen melden spaetere Module ueber {@see ClassLinkGuards} an.
 */
final class Classes
{
    public function __construct(
        private readonly ClassRepository $repository = new ClassRepository(),
        private readonly SchoolYears $schoolYears = new SchoolYears(),
        private readonly AuditLog $audit = new AuditLog(),
        private readonly ClassLinkGuards $linkGuards = new ClassLinkGuards(),
    ) {
    }

    /**
     * Alle Schuljahre mit Status fuer die Auswahl, nach Beginn. Wer Klassen sieht, braucht dafuer kein `school_years.view`.
     *
     * @return list<array{id:string,name:string,starts_on:string,ends_on:string,status:string}>
     */
    public function schoolYears(): array
    {
        return $this->schoolYears->list();
    }

    /**
     * Die Klassen eines Schuljahres: nach Klassenstufe, dann natuerlich nach Bezeichnung (7a, 7b, ..., 10a).
     * Archivierte Klassen nur mit $includeArchived.
     *
     * @return list<array{id:string,grade_level:int,name:string,archived:bool,school_year:array{id:string,name:string}}>
     * @throws CodedException `school_year_not_found` (404)
     */
    public function listIn(string $schoolYearId, bool $includeArchived = false): array
    {
        $year = $this->schoolYear($schoolYearId);
        $rows = $this->repository->inSchoolYear($schoolYearId, $includeArchived);
        usort($rows, static fn (array $a, array $b): int => ($a['grade_level'] <=> $b['grade_level']) ?: strnatcasecmp($a['name'], $b['name']));
        return array_map(static fn (array $r): array => self::present($r, $year), $rows);
    }

    /**
     * Die fuer neue Zuordnungen waehlbaren Klassen, also ohne archivierte: eines Schuljahres oder aller Schuljahre
     * (dann nach Beginn des Schuljahres, innerhalb davon wie {@see self::listIn()}). Spaetere Module fragen hier ab.
     *
     * @return list<array{id:string,grade_level:int,name:string,archived:bool,school_year:array{id:string,name:string}}>
     * @throws CodedException `school_year_not_found` (404)
     */
    public function selectable(?string $schoolYearId = null): array
    {
        if ($schoolYearId !== null) {
            return $this->listIn($schoolYearId);
        }
        $classes = [];
        foreach ($this->schoolYears->list() as $year) {
            array_push($classes, ...$this->listIn($year['id']));
        }
        return $classes;
    }

    /**
     * Legt eine Klasse in einem beliebigen Schuljahr an (laufend, kommend oder vergangen). Die Eindeutigkeit der Bezeichnung
     * sichert der eindeutige Schluessel der Tabelle; Anlegen und Audit-Eintrag `class.created` laufen in einer Transaktion.
     * Wird das Schuljahr zwischen Pruefung und Insert geloescht, meldet der Fremdschluessel es als nicht gefunden.
     *
     * @return array{id:string,grade_level:int,name:string,archived:bool,school_year:array{id:string,name:string}}
     * @throws CodedException `school_year_not_found` (404), `class_name_taken` (409)
     */
    public function create(Actor $actor, ClassInput $input): array
    {
        $year = $this->schoolYear($input->schoolYearId);
        $id = Uuid::v4();
        $this->inTransaction(function () use ($id, $input, $actor): void {
            $this->repository->insert($id, $input);
            $this->audit->record($actor, AuditLog::CLASS_CREATED, 'class', $id, ['name' => $input->change->name]);
        });
        return self::present(['id' => $id, 'grade_level' => $input->change->gradeLevel, 'name' => $input->change->name, 'archived' => false], $year);
    }

    /**
     * Aendert Klassenstufe und Bezeichnung nach denselben Regeln wie beim Anlegen ({@see ClassChange}). Die Eingabe kommt
     * roh und wird erst geprueft, wenn die Klasse gesperrt und als aenderbar erkannt ist: unbekannt (404) und archiviert
     * (409) gehen der Eingabepruefung (400) vor. Das Schuljahr ist nicht aenderbar: ein abweichendes
     * `$claimedSchoolYearId` wird abgelehnt. Eine archivierte Klasse ist schreibgeschuetzt.
     *
     * @return array{id:string,grade_level:int,name:string,archived:bool,school_year:array{id:string,name:string}}
     * @throws CodedException `class_not_found` (404), `class_archived` (409), `class_name_taken` (409), `invalid_request` (400)
     */
    public function update(Actor $actor, string $id, mixed $gradeLevel, mixed $name, mixed $claimedSchoolYearId = null): array
    {
        $before = $change = null;
        $this->inTransaction(function () use ($id, $gradeLevel, $name, $actor, $claimedSchoolYearId, &$before, &$change): void {
            $before = $this->lockedClass($id);
            if ($before['archived']) {
                throw new CodedException('class_archived', 409, 'Die Klasse ist archiviert und kann nicht geändert werden. Bitte zuerst wiederherstellen.');
            }
            if ($claimedSchoolYearId !== null && $claimedSchoolYearId !== $before['school_year_id']) {
                throw new CodedException('invalid_request', 400, 'Das Schuljahr einer Klasse lässt sich nicht ändern.');
            }
            $change = ClassChange::fromInput($gradeLevel, $name);
            $this->repository->update($id, $change);
            $this->audit->record($actor, AuditLog::CLASS_UPDATED, 'class', $id, ['old_name' => $before['name'], 'name' => $change->name]);
        });
        return self::present(['id' => $id, 'grade_level' => $change->gradeLevel, 'name' => $change->name, 'archived' => false], $this->schoolYear($before['school_year_id']));
    }

    /**
     * Archiviert eine Klasse: sie bleibt lesbar, ihre Bezeichnung belegt, aber sie ist schreibgeschuetzt und nicht waehlbar.
     * Geht mit und ohne Verknuepfungen.
     *
     * @return array{id:string,grade_level:int,name:string,archived:bool,school_year:array{id:string,name:string}}
     * @throws CodedException `class_not_found` (404), `class_already_archived` (409)
     */
    public function archive(Actor $actor, string $id): array
    {
        return $this->setArchived($actor, $id, true);
    }

    /**
     * Stellt eine archivierte Klasse wieder her. Ihre Bezeichnung war die ganze Zeit belegt, eine erneute
     * Eindeutigkeitspruefung ist daher nicht noetig.
     *
     * @return array{id:string,grade_level:int,name:string,archived:bool,school_year:array{id:string,name:string}}
     * @throws CodedException `class_not_found` (404), `class_not_archived` (409)
     */
    public function restore(Actor $actor, string $id): array
    {
        return $this->setArchived($actor, $id, false);
    }

    /**
     * Loescht eine Klasse endgueltig, solange nichts an ihr haengt (auch eine archivierte). Sonst wird abgelehnt und auf
     * das Archivieren verwiesen.
     *
     * @throws CodedException `class_not_found` (404), `class_linked` (409)
     */
    public function delete(Actor $actor, string $id): void
    {
        $this->inTransaction(function () use ($id, $actor): void {
            $class = $this->lockedClass($id);
            $this->linkGuards->assertDeletable($id);
            $this->repository->delete($id);
            $this->audit->record($actor, AuditLog::CLASS_DELETED, 'class', $id, ['name' => $class['name']]);
        });
    }

    /**
     * @return array{id:string,grade_level:int,name:string,archived:bool,school_year:array{id:string,name:string}}
     */
    private function setArchived(Actor $actor, string $id, bool $archived): array
    {
        $class = null;
        $this->inTransaction(function () use ($id, $archived, $actor, &$class): void {
            $class = $this->lockedClass($id);
            if ($class['archived'] === $archived) {
                throw $archived
                    ? new CodedException('class_already_archived', 409, 'Die Klasse ist bereits archiviert.')
                    : new CodedException('class_not_archived', 409, 'Die Klasse ist nicht archiviert.');
            }
            $this->repository->setArchived($id, $archived);
            $this->audit->record($actor, $archived ? AuditLog::CLASS_ARCHIVED : AuditLog::CLASS_RESTORED, 'class', $id, ['name' => $class['name']]);
        });
        return self::present([...$class, 'archived' => $archived], $this->schoolYear($class['school_year_id']));
    }

    /**
     * Die Klasse, fuer die Dauer der laufenden Transaktion gesperrt, damit gleichzeitiges Bearbeiten, Archivieren und
     * Loeschen sich nicht ueberholt.
     *
     * @return array{id:string,school_year_id:string,grade_level:int,name:string,archived:bool}
     * @throws CodedException `class_not_found` (404)
     */
    private function lockedClass(string $id): array
    {
        $class = $this->repository->find($id, lock: true);
        if ($class === null) {
            throw new CodedException('class_not_found', 404, 'Die Klasse gibt es nicht.');
        }
        return $class;
    }

    /**
     * Fuehrt die Aenderung samt Audit in einer Transaktion aus. Datenbankfehler der Schluessel werden zu fachlichen: doppelte
     * Bezeichnung ist `class_name_taken` (409); ein Schuljahr, das zwischen Pruefung und Schreiben geloescht wurde, ist
     * `school_year_not_found` (404).
     */
    private function inTransaction(\Closure $change): void
    {
        try {
            Transactions::run($change);
        } catch (\PDOException $e) {
            throw match (MysqlError::of($e)) {
                MysqlError::DUPLICATE_ENTRY => new CodedException('class_name_taken', 409, 'Im Schuljahr gibt es bereits eine Klasse mit dieser Bezeichnung.'),
                MysqlError::NO_REFERENCED_ROW => SchoolYears::notFound(),
                default => $e,
            };
        }
    }

    /**
     * @return array{id:string,name:string}
     * @throws CodedException `school_year_not_found` (404)
     */
    private function schoolYear(string $schoolYearId): array
    {
        $year = $this->schoolYears->get($schoolYearId);
        return ['id' => $year['id'], 'name' => $year['name']];
    }

    /**
     * @param array{id:string,grade_level:int,name:string,archived:bool} $row
     * @param array{id:string,name:string} $year
     * @return array{id:string,grade_level:int,name:string,archived:bool,school_year:array{id:string,name:string}}
     */
    private static function present(array $row, array $year): array
    {
        return ['id' => $row['id'], 'grade_level' => $row['grade_level'], 'name' => $row['name'], 'archived' => $row['archived'], 'school_year' => $year];
    }
}
