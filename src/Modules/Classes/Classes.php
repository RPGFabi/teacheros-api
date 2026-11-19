<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use Hoelzle\Core\Database\DB;
use TeacherOS\Modules\SchoolYears\SchoolYears;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Uuid;

/**
 * Klassen ansehen und anlegen (Ticket #55, GLOSSARY.md: Klasse). Jede Klasse gehoert unveraenderlich zu einem Schuljahr
 * und traegt dessen Kennung und Bezeichnung mit. Schuljahre und ihr Status kommen aus {@see SchoolYears}, nicht von hier.
 */
final class Classes
{
    private const MYSQL_DUPLICATE_KEY = 1062;

    public function __construct(
        private readonly ClassRepository $repository = new ClassRepository(),
        private readonly SchoolYears $schoolYears = new SchoolYears(),
        private readonly AuditLog $audit = new AuditLog(),
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
     *
     * @return list<array{id:string,grade_level:int,name:string,school_year:array{id:string,name:string}}>
     * @throws CodedException `school_year_not_found` (404)
     */
    public function listIn(string $schoolYearId): array
    {
        $year = $this->schoolYear($schoolYearId);
        $rows = $this->repository->inSchoolYear($schoolYearId);
        usort($rows, static fn (array $a, array $b): int => ($a['grade_level'] <=> $b['grade_level']) ?: strnatcasecmp($a['name'], $b['name']));
        return array_map(static fn (array $r): array => self::present($r, $year), $rows);
    }

    /**
     * Legt eine Klasse in einem beliebigen Schuljahr an (laufend, kommend oder vergangen). Die Eindeutigkeit der Bezeichnung
     * sichert der eindeutige Schluessel der Tabelle; Anlegen und Audit-Eintrag `class.created` laufen in einer Transaktion.
     *
     * @return array{id:string,grade_level:int,name:string,school_year:array{id:string,name:string}}
     * @throws CodedException `school_year_not_found` (404), `class_name_taken` (409)
     */
    public function create(Actor $actor, ClassInput $input): array
    {
        $year = $this->schoolYear($input->schoolYearId);
        $id = Uuid::v4();
        $pdo = DB::getInstance();
        $pdo->beginTransaction();
        try {
            $this->repository->insert($id, $input);
            $this->audit->record($actor, AuditLog::CLASS_CREATED, 'class', $id, [
                'name' => $input->name,
                'grade_level' => $input->gradeLevel,
                'school_year_id' => $input->schoolYearId,
            ]);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if (($e->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_KEY) {
                throw new CodedException('class_name_taken', 409, 'Im Schuljahr gibt es bereits eine Klasse mit dieser Bezeichnung.');
            }
            throw $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::present(['id' => $id, 'grade_level' => $input->gradeLevel, 'name' => $input->name], $year);
    }

    /**
     * @return array{id:string,name:string}
     * @throws CodedException `school_year_not_found` (404)
     */
    private function schoolYear(string $schoolYearId): array
    {
        $year = $this->schoolYears->find($schoolYearId);
        if ($year === null) {
            throw new CodedException('school_year_not_found', 404, 'Das Schuljahr gibt es nicht.');
        }
        return ['id' => $year['id'], 'name' => $year['name']];
    }

    /**
     * @param array{id:string,grade_level:int,name:string} $row
     * @param array{id:string,name:string} $year
     * @return array{id:string,grade_level:int,name:string,school_year:array{id:string,name:string}}
     */
    private static function present(array $row, array $year): array
    {
        return ['id' => $row['id'], 'grade_level' => $row['grade_level'], 'name' => $row['name'], 'school_year' => $year];
    }
}
