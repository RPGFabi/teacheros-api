<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Assignments;

use TeacherOS\Modules\SchoolSettings\SchoolLimits;
use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYears;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Clock\SystemClock;
use TeacherOS\Shared\Database\MysqlError;
use TeacherOS\Shared\Database\Transactions;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Uuid;

/**
 * Lehrkraefte einem Elternobjekt zuordnen, beenden und loeschen (GLOSSARY.md: Lehrkraft-Zuordnung, Klassenleitung): der eine
 * Dienst fuer Lehrkraft-Zuordnungen zu Fachgruppen (Ticket #67) und Klassenleitungen (Ticket #68). Woran die Zuordnung haengt
 * (Tabelle, Elternobjekt, Audit, Fehlercodes, Obergrenze), sagt die {@see AssignmentKind}; die Regeln (Gueltigkeitszeitraum,
 * Ueberschneidung, Obergrenze, Beenden, Loeschen) stehen in {@see AssignmentRules}; wer Lehrkraft ist, entscheidet
 * {@see Teachers}. Hier steht nur das Zusammenspiel: Sperren, Speichern, Audit. Einem archivierten Elternobjekt (Fachgruppe #65,
 * Klasse #57) wird nichts neu zugeordnet; Archivieren beendet laufende Zuordnungen nicht, sie lassen sich weiter beenden.
 */
final class Assignments
{
    private readonly AssignmentRepository $repository;

    public function __construct(
        private readonly AssignmentKind $kind,
        ?AssignmentRepository $repository = null,
        private readonly SchoolYears $schoolYears = new SchoolYears(),
        private readonly Teachers $teachers = new Teachers(),
        private readonly SchoolLimits $limits = new SchoolLimits(),
        private readonly AuditLog $audit = new AuditLog(),
        private readonly AssignmentRules $rules = new AssignmentRules(new SystemClock()),
    ) {
        $this->repository = $repository ?? new AssignmentRepository($kind);
    }

    /**
     * Die waehlbaren Lehrkraefte fuer das Formular (mit E-Mail und Kontostatus, nur fuer die Verwaltung).
     *
     * @return list<Teacher>
     */
    public function selectableTeachers(): array
    {
        return $this->teachers->selectable();
    }

    /**
     * Die Zuordnungen eines Elternobjekts, nach Beginn.
     *
     * @return list<AssignmentView>
     * @throws CodedException `<Elternobjekt>_not_found` (404)
     */
    public function forParent(string $parentId): array
    {
        $parent = $this->repository->parent($parentId) ?? throw $this->kind->parentNotFound();
        return $this->present($this->repository->forParent($parentId), $this->schoolYears->get($parent['school_year_id']));
    }

    /**
     * Ordnet eine Lehrkraft einem Elternobjekt zu. Unter der Sperre des Elternobjekts (gleichzeitige Zuordnungen laufen
     * nacheinander, die Obergrenze haelt) und mit gemeinsamer Sperre des Schuljahres (ein gleichzeitiges Aendern des
     * Schuljahres sieht die neue Zuordnung, {@see AssignmentSchoolYearGuard}); Anlegen und Audit in einer Transaktion. Ohne
     * `$startsOn` beginnt sie am ersten Tag des Schuljahres, ohne `$endsOn` ist sie offen (bis Schuljahresende).
     *
     * @throws CodedException `<Elternobjekt>_not_found` (404), `<Elternobjekt>_archived` (409), `invalid_validity`/`validity_outside_school_year` (422),
     *     `teacher_not_selectable` (409), `assignment_overlap` (409), `assignment_limit_reached` (409)
     */
    public function create(Actor $actor, string $parentId, string $accountId, mixed $startsOn, mixed $endsOn): AssignmentView
    {
        $id = Uuid::v4();
        $created = null;
        $this->inTransaction(function () use ($actor, $parentId, $accountId, $startsOn, $endsOn, $id, &$created): void {
            $parent = $this->repository->parent($parentId, lock: true) ?? throw $this->kind->parentNotFound();
            if ($parent['archived']) {
                throw $this->kind->parentArchived();
            }
            $this->repository->lockSchoolYear($parent['school_year_id'], exclusive: false);
            $year = $this->schoolYears->get($parent['school_year_id']);
            $validity = $this->rules->validity($year, $startsOn, $endsOn);
            $this->teachers->assertSelectable($accountId);
            $this->rules->assertCanAdd($year, $validity, $accountId, $this->assignments($parentId), $this->kind->maxPerParent($this->limits));
            $this->repository->insert($id, $parentId, $accountId, $validity);
            $this->audit->record($actor, $this->kind->auditCreated, $this->kind->objectType, $id, $this->context($parentId, $accountId, $validity));
            $created = [$year, $validity];
        });
        [$year, $validity] = $created;
        return $this->present([['id' => $id, 'parent_id' => $parentId, 'account_id' => $accountId, 'valid_from' => $validity->validFrom, 'valid_until' => $validity->validUntil]], $year)[0];
    }

    /**
     * Beendet eine Zuordnung: setzt das Ende einer offenen oder verschiebt ein in der Zukunft liegendes Ende nach vorn; ohne
     * `$endsOn` endet sie heute. Nie rueckwirkend, der Beginn bleibt ({@see AssignmentRules::ended()}).
     *
     * @throws CodedException `<Zuordnung>_not_found` (404), `assignment_ended` (409), `assignment_applied` (409), `invalid_validity` (422), `invalid_request` (400)
     */
    public function end(Actor $actor, string $id, mixed $endsOn): AssignmentView
    {
        $row = $year = $validity = null;
        $this->inTransaction(function () use ($actor, $id, $endsOn, &$row, &$year, &$validity): void {
            $row = $this->lockedAssignment($id);
            $year = $this->yearOf($row['parent_id']);
            $validity = $this->rules->ended($year, self::assignment($row), $endsOn);
            $this->repository->updateUntil($id, (string) $validity->validUntil);
            $this->audit->record($actor, $this->kind->auditEnded, $this->kind->objectType, $id, $this->context($row['parent_id'], $row['account_id'], $validity));
        });
        return $this->present([[...$row, 'valid_until' => $validity->validUntil]], $year)[0];
    }

    /**
     * Loescht eine Zuordnung, die noch nie gegolten hat (Beginn in der Zukunft).
     *
     * @throws CodedException `<Zuordnung>_not_found` (404), `assignment_applied` (409)
     */
    public function delete(Actor $actor, string $id): void
    {
        $this->inTransaction(function () use ($actor, $id): void {
            $row = $this->lockedAssignment($id);
            $assignment = self::assignment($row);
            $this->rules->assertDeletable($assignment);
            $this->repository->delete($id);
            $this->audit->record($actor, $this->kind->auditDeleted, $this->kind->objectType, $id, $this->context($row['parent_id'], $row['account_id'], $assignment->validity));
        });
    }

    /** @return list<Assignment> die Zuordnungen des Elternobjekts in der Form der Regeln */
    private function assignments(string $parentId): array
    {
        return array_map(self::assignment(...), $this->repository->forParent($parentId));
    }

    /** @param array{id:string,account_id:string,valid_from:string,valid_until:?string} $row */
    private static function assignment(array $row): Assignment
    {
        return new Assignment($row['id'], $row['account_id'], new Validity($row['valid_from'], $row['valid_until']));
    }

    /** @return array<string,scalar|null> der Kontext der Audit-Eintraege: Elternobjekt, Lehrkraft (Kennung des Kontos), erster und letzter Tag */
    private function context(string $parentId, string $accountId, Validity $validity): array
    {
        return [$this->kind->parentColumn => $parentId, 'teacher_id' => $accountId, ...$validity->toArray()];
    }

    /**
     * @param list<array{id:string,parent_id:string,account_id:string,valid_from:string,valid_until:?string}> $rows
     * @return list<AssignmentView>
     */
    private function present(array $rows, SchoolYear $year): array
    {
        $teachers = $this->teachers->refs(array_column($rows, 'account_id'));
        return array_map(function (array $row) use ($year, $teachers): AssignmentView {
            $validity = new Validity($row['valid_from'], $row['valid_until']);
            return new AssignmentView($this->kind, $row['id'], $row['parent_id'], $teachers[$row['account_id']], $validity, $this->rules->status($year, $validity), $year);
        }, $rows);
    }

    /**
     * @return array{id:string,parent_id:string,account_id:string,valid_from:string,valid_until:?string}
     * @throws CodedException `<Zuordnung>_not_found` (404)
     */
    private function lockedAssignment(string $id): array
    {
        return $this->repository->find($id, lock: true) ?? throw $this->kind->notFound();
    }

    private function yearOf(string $parentId): SchoolYear
    {
        $parent = $this->repository->parent($parentId) ?? throw new \LogicException('Das Elternobjekt der Zuordnung fehlt.');
        return $this->schoolYears->get($parent['school_year_id']);
    }

    /** Fuehrt die Aenderung samt Audit in einer Transaktion aus; ein zwischen Pruefung und Schreiben verschwundenes Elternobjekt ist `<Elternobjekt>_not_found`. */
    private function inTransaction(\Closure $change): void
    {
        try {
            Transactions::run($change);
        } catch (\PDOException $e) {
            throw MysqlError::of($e) === MysqlError::NO_REFERENCED_ROW ? $this->kind->parentNotFound() : $e;
        }
    }
}
