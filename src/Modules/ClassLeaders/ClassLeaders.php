<?php

declare(strict_types=1);

namespace TeacherOS\Modules\ClassLeaders;

use TeacherOS\Modules\Classes\ClassRepository;
use TeacherOS\Modules\SchoolSettings\SchoolLimits;
use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYears;
use TeacherOS\Modules\TeacherAssignments\Assignment;
use TeacherOS\Modules\TeacherAssignments\AssignmentPeriod;
use TeacherOS\Modules\TeacherAssignments\AssignmentRules;
use TeacherOS\Modules\TeacherAssignments\Teachers;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Clock\SystemClock;
use TeacherOS\Shared\Database\MysqlError;
use TeacherOS\Shared\Database\Transactions;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Uuid;

/**
 * Lehrkraefte als Klassenleitung einer Klasse einsetzen, beenden und loeschen (Ticket #68, GLOSSARY.md: Klassenleitung). Die
 * Regeln (Zeitraum, Ueberschneidung, Obergrenze, Beenden, Loeschen) sind die der Lehrkraft-Zuordnung zu Fachgruppen und liegen
 * unveraendert in {@see AssignmentRules} (Ticket #67); wer Lehrkraft ist, entscheidet {@see Teachers}; die Obergrenze liefert
 * {@see SchoolLimits::maxClassLeadersPerClass()}. Hier steht nur, was an der Klasse haengt: Sperren, Speichern, Audit.
 * Einer archivierten Klasse (Ticket #57) wird keine Klassenleitung neu zugeordnet; Archivieren beendet laufende nicht, sie
 * lassen sich weiter beenden. Dass eine Klasse mit Klassenleitung als verknuepft gilt, meldet {@see ClassLeadersClassLinkGuard}.
 */
final class ClassLeaders
{
    public function __construct(
        private readonly ClassLeaderRepository $repository = new ClassLeaderRepository(),
        private readonly ClassRepository $classes = new ClassRepository(),
        private readonly SchoolYears $schoolYears = new SchoolYears(),
        private readonly Teachers $teachers = new Teachers(),
        private readonly SchoolLimits $limits = new SchoolLimits(),
        private readonly AuditLog $audit = new AuditLog(),
        private readonly AssignmentRules $rules = new AssignmentRules(new SystemClock()),
    ) {
    }

    /**
     * Die Klassenleitungen einer Klasse, nach Beginn.
     *
     * @return list<ClassLeader>
     * @throws CodedException `class_not_found` (404)
     */
    public function forClass(string $classId): array
    {
        $class = $this->classes->find($classId) ?? throw self::classNotFound();
        return $this->present($this->repository->forClass($classId), $this->schoolYears->get($class['school_year_id']));
    }

    /**
     * Setzt eine Lehrkraft als Klassenleitung ein. Unter der Sperre der Klasse (gleichzeitiges Einsetzen laeuft nacheinander,
     * die Obergrenze haelt) und mit gemeinsamer Sperre des Schuljahres (ein gleichzeitiges Aendern des Schuljahres sieht die neue
     * Klassenleitung, {@see ClassLeadersSchoolYearGuard}); Anlegen und Audit `class_lead.created` in einer Transaktion.
     * Ohne `$startsOn` beginnt sie am ersten Tag des Schuljahres, ohne `$endsOn` ist sie offen (bis Schuljahresende).
     *
     * @throws CodedException `class_not_found` (404), `class_archived` (409), `invalid_period`/`period_outside_school_year` (422),
     *     `teacher_not_selectable` (409), `assignment_overlap` (409), `assignment_limit_reached` (409)
     */
    public function create(Actor $actor, string $classId, string $accountId, mixed $startsOn, mixed $endsOn): ClassLeader
    {
        $id = Uuid::v4();
        $created = null;
        $this->inTransaction(function () use ($actor, $classId, $accountId, $startsOn, $endsOn, $id, &$created): void {
            $class = $this->classes->find($classId, lock: true) ?? throw self::classNotFound();
            if ($class['archived']) {
                throw new CodedException('class_archived', 409, 'Die Klasse ist archiviert. Ihr lässt sich keine Klassenleitung neu zuordnen; bestehende Klassenleitungen bleiben und lassen sich beenden.');
            }
            $this->repository->lockSchoolYear($class['school_year_id'], exclusive: false);
            $year = $this->schoolYears->get($class['school_year_id']);
            $period = $this->rules->period($year, $startsOn, $endsOn);
            $this->teachers->assertSelectable($accountId);
            $this->rules->assertCanAdd($year, $period, $accountId, $this->assignments($classId), $this->limits->maxClassLeadersPerClass());
            $this->repository->insert($id, $classId, $accountId, $period);
            $this->audit->record($actor, AuditLog::CLASS_LEAD_CREATED, 'class_lead', $id, self::context($classId, $accountId, $period));
            $created = [$year, $period];
        });
        [$year, $period] = $created;
        return $this->present([['id' => $id, 'class_id' => $classId, 'account_id' => $accountId, 'valid_from' => $period->validFrom, 'valid_until' => $period->validUntil]], $year)[0];
    }

    /**
     * Beendet eine Klassenleitung: setzt das Ende einer offenen oder verschiebt ein in der Zukunft liegendes Ende nach vorn; ohne
     * `$endsOn` endet sie heute. Nie rueckwirkend, der Beginn bleibt ({@see AssignmentRules::ended()}). Audit `class_lead.ended`.
     *
     * @throws CodedException `class_leader_not_found` (404), `assignment_ended` (409), `assignment_applied` (409), `invalid_period` (422), `invalid_request` (400)
     */
    public function end(Actor $actor, string $id, mixed $endsOn): ClassLeader
    {
        $row = $year = $period = null;
        $this->inTransaction(function () use ($actor, $id, $endsOn, &$row, &$year, &$period): void {
            $row = $this->lockedLeader($id);
            $year = $this->yearOf($row['class_id']);
            $period = $this->rules->ended($year, self::assignment($row), $endsOn);
            $this->repository->updateUntil($id, (string) $period->validUntil);
            $this->audit->record($actor, AuditLog::CLASS_LEAD_ENDED, 'class_lead', $id, self::context($row['class_id'], $row['account_id'], $period));
        });
        return $this->present([[...$row, 'valid_until' => $period->validUntil]], $year)[0];
    }

    /**
     * Loescht eine Klassenleitung, die noch nie gegolten hat (Beginn in der Zukunft). Audit `class_lead.deleted`.
     *
     * @throws CodedException `class_leader_not_found` (404), `assignment_applied` (409)
     */
    public function delete(Actor $actor, string $id): void
    {
        $this->inTransaction(function () use ($actor, $id): void {
            $row = $this->lockedLeader($id);
            $assignment = self::assignment($row);
            $this->rules->assertDeletable($assignment);
            $this->repository->delete($id);
            $this->audit->record($actor, AuditLog::CLASS_LEAD_DELETED, 'class_lead', $id, self::context($row['class_id'], $row['account_id'], $assignment->period));
        });
    }

    /**
     * @return array{id:string,class_id:string,account_id:string,valid_from:string,valid_until:?string}
     * @throws CodedException `class_leader_not_found` (404)
     */
    private function lockedLeader(string $id): array
    {
        return $this->repository->find($id, lock: true) ?? throw new CodedException('class_leader_not_found', 404, 'Die Klassenleitung gibt es nicht.');
    }

    private function yearOf(string $classId): SchoolYear
    {
        $class = $this->classes->find($classId) ?? throw new \LogicException('Die Klasse der Klassenleitung fehlt.');
        return $this->schoolYears->get($class['school_year_id']);
    }

    /** @return list<Assignment> die Klassenleitungen der Klasse in der Form der Regeln */
    private function assignments(string $classId): array
    {
        return array_map(self::assignment(...), $this->repository->forClass($classId));
    }

    /** @param array{id:string,account_id:string,valid_from:string,valid_until:?string} $row */
    private static function assignment(array $row): Assignment
    {
        return new Assignment($row['id'], $row['account_id'], new AssignmentPeriod($row['valid_from'], $row['valid_until']));
    }

    /** @return array{class_id:string,teacher_id:string,starts_on:string,ends_on:?string} der Kontext der Audit-Eintraege */
    private static function context(string $classId, string $accountId, AssignmentPeriod $period): array
    {
        return ['class_id' => $classId, 'teacher_id' => $accountId, ...$period->toArray()];
    }

    /**
     * @param list<array{id:string,class_id:string,account_id:string,valid_from:string,valid_until:?string}> $rows
     * @return list<ClassLeader>
     */
    private function present(array $rows, SchoolYear $year): array
    {
        $teachers = $this->teachers->byIds(array_column($rows, 'account_id'));
        return array_map(function (array $row) use ($year, $teachers): ClassLeader {
            $period = new AssignmentPeriod($row['valid_from'], $row['valid_until']);
            return new ClassLeader($row['id'], $row['class_id'], $teachers[$row['account_id']], $period, $this->rules->status($year, $period), $year);
        }, $rows);
    }

    private static function classNotFound(): CodedException
    {
        return new CodedException('class_not_found', 404, 'Die Klasse gibt es nicht.');
    }

    /** Fuehrt die Aenderung samt Audit in einer Transaktion aus; eine zwischen Pruefung und Schreiben verschwundene Klasse ist `class_not_found`. */
    private function inTransaction(\Closure $change): void
    {
        try {
            Transactions::run($change);
        } catch (\PDOException $e) {
            throw MysqlError::of($e) === MysqlError::NO_REFERENCED_ROW ? self::classNotFound() : $e;
        }
    }
}
