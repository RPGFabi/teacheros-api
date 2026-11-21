<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Modules\SchoolSettings\SchoolLimits;
use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYears;
use TeacherOS\Modules\TeachingGroups\TeachingGroupRepository;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Clock\SystemClock;
use TeacherOS\Shared\Database\MysqlError;
use TeacherOS\Shared\Database\Transactions;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Uuid;

/**
 * Lehrkraefte Fachgruppen zuordnen, beenden und loeschen (Ticket #67, GLOSSARY.md: Lehrkraft-Zuordnung). Die Regeln
 * (Zeitraum, Ueberschneidung, Obergrenze, Beenden, Loeschen) liegen in {@see AssignmentRules} und gelten unveraendert fuer
 * Klassenleitungen (#68); wer Lehrkraft ist, entscheidet {@see Teachers}; die Obergrenze liefert
 * {@see SchoolLimits::maxTeachersPerTeachingGroup()}. Hier steht nur, was an der Fachgruppe haengt: Sperren, Speichern, Audit.
 * Noetige Daten kommen ueber Dienste ({@see SchoolYears}, {@see Teachers}); die Fachgruppe liest der Dienst ueber ihren Speicher.
 * Einer archivierten Fachgruppe (Ticket #65) wird nichts neu zugeordnet; Archivieren beendet laufende Zuordnungen nicht, sie
 * lassen sich weiter beenden. Dass eine Fachgruppe mit Zuordnungen als verknuepft gilt, meldet {@see TeacherAssignmentsLinkGuard}.
 */
final class TeacherAssignments
{
    public function __construct(
        private readonly TeacherAssignmentRepository $repository = new TeacherAssignmentRepository(),
        private readonly TeachingGroupRepository $groups = new TeachingGroupRepository(),
        private readonly SchoolYears $schoolYears = new SchoolYears(),
        private readonly Teachers $teachers = new Teachers(),
        private readonly SchoolLimits $limits = new SchoolLimits(),
        private readonly AuditLog $audit = new AuditLog(),
        private readonly AssignmentRules $rules = new AssignmentRules(new SystemClock()),
    ) {
    }

    /**
     * Die waehlbaren Lehrkraefte fuer das Formular.
     *
     * @return list<Teacher>
     */
    public function selectableTeachers(): array
    {
        return $this->teachers->selectable();
    }

    /**
     * Die Zuordnungen einer Fachgruppe, nach Beginn.
     *
     * @return list<TeacherAssignment>
     * @throws CodedException `teaching_group_not_found` (404)
     */
    public function forGroup(string $teachingGroupId): array
    {
        $group = $this->groups->find($teachingGroupId) ?? throw self::groupNotFound();
        return $this->present($this->repository->forGroup($teachingGroupId), $this->schoolYears->get($group['school_year_id']));
    }

    /**
     * Ordnet eine Lehrkraft einer Fachgruppe zu. Unter der Sperre der Fachgruppe (gleichzeitige Zuordnungen laufen nacheinander,
     * die Obergrenze haelt) und mit gemeinsamer Sperre des Schuljahres (ein gleichzeitiges Aendern des Schuljahres sieht die neue
     * Zuordnung, {@see TeacherAssignmentsSchoolYearGuard}); Anlegen und Audit `teacher_assignment.created` in einer Transaktion.
     * Ohne `$startsOn` beginnt sie am ersten Tag des Schuljahres, ohne `$endsOn` ist sie offen (bis Schuljahresende).
     *
     * @throws CodedException `teaching_group_not_found` (404), `teaching_group_archived` (409), `invalid_period`/`period_outside_school_year` (422),
     *     `teacher_not_selectable` (409), `assignment_overlap` (409), `assignment_limit_reached` (409)
     */
    public function create(Actor $actor, string $teachingGroupId, string $accountId, mixed $startsOn, mixed $endsOn): TeacherAssignment
    {
        $id = Uuid::v4();
        $created = null;
        $this->inTransaction(function () use ($actor, $teachingGroupId, $accountId, $startsOn, $endsOn, $id, &$created): void {
            $group = $this->repository->lockGroup($teachingGroupId) ?? throw self::groupNotFound();
            if ($group['archived']) {
                throw new CodedException('teaching_group_archived', 409, 'Die Fachgruppe ist archiviert. Ihr lassen sich keine Lehrkräfte neu zuordnen; bestehende Zuordnungen bleiben und lassen sich beenden.');
            }
            $this->repository->lockSchoolYear($group['school_year_id'], exclusive: false);
            $year = $this->schoolYears->get($group['school_year_id']);
            $period = $this->rules->period($year, $startsOn, $endsOn);
            $this->teachers->assertSelectable($accountId);
            $this->rules->assertCanAdd($year, $period, $accountId, $this->assignments($teachingGroupId), $this->limits->maxTeachersPerTeachingGroup());
            $this->repository->insert($id, $teachingGroupId, $accountId, $period);
            $this->audit->record($actor, AuditLog::TEACHER_ASSIGNMENT_CREATED, 'teacher_assignment', $id, self::context($teachingGroupId, $accountId, $period));
            $created = [$year, $period];
        });
        [$year, $period] = $created;
        return $this->present([['id' => $id, 'teaching_group_id' => $teachingGroupId, 'account_id' => $accountId, 'valid_from' => $period->validFrom, 'valid_until' => $period->validUntil]], $year)[0];
    }

    /**
     * Beendet eine Zuordnung: setzt das Ende einer offenen oder verschiebt ein in der Zukunft liegendes Ende nach vorn; ohne
     * `$endsOn` endet sie heute. Nie rueckwirkend, der Beginn bleibt ({@see AssignmentRules::ended()}). Audit
     * `teacher_assignment.ended`.
     *
     * @throws CodedException `teacher_assignment_not_found` (404), `assignment_ended` (409), `assignment_applied` (409), `invalid_period` (422), `invalid_request` (400)
     */
    public function end(Actor $actor, string $id, mixed $endsOn): TeacherAssignment
    {
        $row = $year = $period = null;
        $this->inTransaction(function () use ($actor, $id, $endsOn, &$row, &$year, &$period): void {
            $row = $this->lockedAssignment($id);
            $year = $this->yearOf($row['teaching_group_id']);
            $period = $this->rules->ended($year, self::assignment($row), $endsOn);
            $this->repository->updateUntil($id, (string) $period->validUntil);
            $this->audit->record($actor, AuditLog::TEACHER_ASSIGNMENT_ENDED, 'teacher_assignment', $id, self::context($row['teaching_group_id'], $row['account_id'], $period));
        });
        return $this->present([[...$row, 'valid_until' => $period->validUntil]], $year)[0];
    }

    /**
     * Loescht eine Zuordnung, die noch nie gegolten hat (Beginn in der Zukunft). Audit `teacher_assignment.deleted`.
     *
     * @throws CodedException `teacher_assignment_not_found` (404), `assignment_applied` (409)
     */
    public function delete(Actor $actor, string $id): void
    {
        $this->inTransaction(function () use ($actor, $id): void {
            $row = $this->lockedAssignment($id);
            $assignment = self::assignment($row);
            $this->rules->assertDeletable($assignment);
            $year = $this->yearOf($row['teaching_group_id']);
            $this->repository->delete($id);
            $this->audit->record($actor, AuditLog::TEACHER_ASSIGNMENT_DELETED, 'teacher_assignment', $id, self::context($row['teaching_group_id'], $row['account_id'], $assignment->period));
        });
    }

    /** @return list<Assignment> die Zuordnungen der Fachgruppe in der Form der Regeln */
    private function assignments(string $teachingGroupId): array
    {
        return array_map(self::assignment(...), $this->repository->forGroup($teachingGroupId));
    }

    /** @param array{id:string,account_id:string,valid_from:string,valid_until:?string} $row */
    private static function assignment(array $row): Assignment
    {
        return new Assignment($row['id'], $row['account_id'], new AssignmentPeriod($row['valid_from'], $row['valid_until']));
    }

    /** @return array{teaching_group_id:string,teacher_id:string,starts_on:string,ends_on:?string} der Kontext der Audit-Eintraege */
    private static function context(string $teachingGroupId, string $accountId, AssignmentPeriod $period): array
    {
        return ['teaching_group_id' => $teachingGroupId, 'teacher_id' => $accountId, ...$period->toArray()];
    }

    /**
     * @param list<array{id:string,teaching_group_id:string,account_id:string,valid_from:string,valid_until:?string}> $rows
     * @return list<TeacherAssignment>
     */
    private function present(array $rows, SchoolYear $year): array
    {
        $teachers = $this->teachers->byIds(array_column($rows, 'account_id'));
        return array_map(function (array $row) use ($year, $teachers): TeacherAssignment {
            $period = new AssignmentPeriod($row['valid_from'], $row['valid_until']);
            return new TeacherAssignment($row['id'], $row['teaching_group_id'], $teachers[$row['account_id']], $period, $this->rules->status($year, $period), $year);
        }, $rows);
    }

    /**
     * @return array{id:string,teaching_group_id:string,account_id:string,valid_from:string,valid_until:?string}
     * @throws CodedException `teacher_assignment_not_found` (404)
     */
    private function lockedAssignment(string $id): array
    {
        return $this->repository->find($id, lock: true) ?? throw new CodedException('teacher_assignment_not_found', 404, 'Die Zuordnung gibt es nicht.');
    }

    private function yearOf(string $teachingGroupId): SchoolYear
    {
        $group = $this->groups->find($teachingGroupId) ?? throw new \LogicException('Die Fachgruppe der Zuordnung fehlt.');
        return $this->schoolYears->get($group['school_year_id']);
    }

    private static function groupNotFound(): CodedException
    {
        return new CodedException('teaching_group_not_found', 404, 'Die Fachgruppe gibt es nicht.');
    }

    /** Fuehrt die Aenderung samt Audit in einer Transaktion aus; eine zwischen Pruefung und Schreiben verschwundene Fachgruppe ist `teaching_group_not_found`. */
    private function inTransaction(\Closure $change): void
    {
        try {
            Transactions::run($change);
        } catch (\PDOException $e) {
            throw MysqlError::of($e) === MysqlError::NO_REFERENCED_ROW ? self::groupNotFound() : $e;
        }
    }
}
