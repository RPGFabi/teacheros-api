<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Shared\Clock\Clock;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Regeln der Lehrkraft-Zuordnung (GLOSSARY.md: Lehrkraft-Zuordnung, Klassenleitung, Gueltigkeitszeitraum), unabhaengig
 * davon, woran die Zuordnung haengt: rein, ohne Datenbank, mit einer injizierten {@see Clock} fuer "heute". Der Aufrufer
 * (Lehrkraft-Zuordnung zu Fachgruppen in #67, Klassenleitung in #68) liest die bestehenden Zuordnungen des Objekts als
 * {@see Assignment}, fragt hier und schreibt selbst. Alle Zeitraeume liegen im Schuljahr des Objekts, das als
 * {@see SchoolYear} mitgegeben wird.
 *
 * - {@see self::period()}: Zeitraum aus der Eingabe, immer innerhalb des Schuljahres
 * - {@see self::assertCanAdd()}: keine Ueberschneidung derselben Lehrkraft, an keinem Tag mehr als die Obergrenze
 * - {@see self::ended()}: Beenden, nie rueckwirkend, der Beginn bleibt
 * - {@see self::assertDeletable()}: Loeschen nur, was noch nie gegolten hat
 * - {@see self::status()}: kommend, laufend oder beendet
 *
 * Fehlercodes sind allgemein gehalten (`assignment_*`), damit sie fuer Fachgruppen und Klassen gleich lauten.
 */
final class AssignmentRules
{
    public const STATUS_UPCOMING = 'upcoming';
    public const STATUS_RUNNING = 'running';
    public const STATUS_ENDED = 'ended';

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * Der Zeitraum aus der Eingabe: Beginn (ohne Angabe der erste Tag des Schuljahres) und Ende einschliesslich (ohne
     * Angabe offen, also bis zum Schuljahresende). Das Ende wird als exklusive Obergrenze gespeichert.
     *
     * @throws CodedException `invalid_request` (400), `period_outside_school_year` (422), `invalid_period` (422)
     */
    public function period(SchoolYear $year, mixed $startsOn, mixed $endsOn): AssignmentPeriod
    {
        $from = $startsOn === null ? $year->startsOn : AssignmentPeriod::day($startsOn, 'den Beginn');
        $last = $endsOn === null ? null : AssignmentPeriod::day($endsOn, 'das Ende');
        foreach ([$from, $last] as $day) {
            if ($day !== null && ($day < $year->startsOn || $day > $year->endsOn)) {
                throw new CodedException('period_outside_school_year', 422, 'Der Gültigkeitszeitraum muss innerhalb des Schuljahres (' . $year->name . ') liegen.');
            }
        }
        if ($last !== null && $last < $from) {
            throw new CodedException('invalid_period', 422, 'Das Ende darf nicht vor dem Beginn liegen.');
        }
        return new AssignmentPeriod($from, $last === null ? null : AssignmentPeriod::nextDay($last));
    }

    /**
     * Darf eine Zuordnung mit diesem Zeitraum fuer die Lehrkraft $teacherId neu hinzukommen? Dieselbe Lehrkraft nicht mit
     * sich ueberschneidendem Zeitraum, nacheinander ist erlaubt; an keinem Tag des neuen Zeitraums mehr als $max
     * Lehrkraefte zugleich. Bestehendes jenseits der Obergrenze (nach Verschaerfung der Schuleinstellung) bleibt
     * unberuehrt: nur die Tage des neuen Zeitraums zaehlen.
     *
     * @param list<Assignment> $existing die bestehenden Zuordnungen desselben Objekts (alle Lehrkraefte)
     * @throws CodedException `assignment_overlap` (409), `assignment_limit_reached` (409)
     */
    public function assertCanAdd(SchoolYear $year, AssignmentPeriod $period, string $teacherId, array $existing, int $max): void
    {
        foreach ($existing as $other) {
            if ($other->teacherId === $teacherId && $other->period->overlaps($period, $year)) {
                throw new CodedException('assignment_overlap', 409, 'Die Lehrkraft ist an diesen Tagen schon zugeordnet. Gültigkeitszeiträume derselben Lehrkraft dürfen sich nicht überschneiden.');
            }
        }
        // Die Zahl gleichzeitiger Zuordnungen aendert sich nur an einem Beginn; es genuegt, den Beginn des neuen Zeitraums
        // und jeden Beginn einer bestehenden Zuordnung darin zu pruefen.
        $days = [$period->validFrom];
        foreach ($existing as $other) {
            if ($period->coversDay($other->period->validFrom, $year)) {
                $days[] = $other->period->validFrom;
            }
        }
        foreach ($days as $day) {
            $simultaneous = count(array_filter($existing, static fn (Assignment $other): bool => $other->period->coversDay($day, $year)));
            if ($simultaneous + 1 > $max) {
                throw new CodedException('assignment_limit_reached', 409, "An mindestens einem Tag des Gültigkeitszeitraums sind bereits {$simultaneous} Lehrkräfte zugleich zugeordnet; die Schule erlaubt höchstens {$max} (Schuleinstellung).");
            }
        }
    }

    /**
     * Der Zeitraum nach dem Beenden: das Ende (einschliesslich, ohne Angabe heute) einer offenen Zuordnung wird gesetzt,
     * ein in der Zukunft liegendes Ende vorverlegt. Der Beginn bleibt, nie wird rueckwirkend geaendert: das neue Ende liegt
     * nicht vor heute (der heutige Tag zaehlt als schon gegolten) und nicht vor dem Beginn, und es liegt vor dem bisherigen Ende.
     *
     * @throws CodedException `assignment_ended` (409), `invalid_request` (400), `invalid_period` (422), `assignment_applied` (409)
     */
    public function ended(SchoolYear $year, Assignment $assignment, mixed $endsOn): AssignmentPeriod
    {
        $today = $this->clock->today();
        $currentLast = $assignment->period->lastDay($year);
        if ($currentLast < $today) {
            throw new CodedException('assignment_ended', 409, 'Die Zuordnung ist bereits beendet.');
        }
        $last = $endsOn === null ? $today : AssignmentPeriod::day($endsOn, 'das Ende');
        if ($last < $assignment->period->validFrom) {
            throw new CodedException('invalid_period', 422, 'Das Ende darf nicht vor dem Beginn der Zuordnung liegen.');
        }
        if ($last < $today) {
            throw new CodedException('assignment_applied', 409, 'Die Zuordnung hat bis heute schon gegolten und lässt sich nicht rückwirkend kürzen. Das Ende kann frühestens heute sein.');
        }
        if ($last >= $currentLast) {
            throw new CodedException('invalid_period', 422, 'Das neue Ende muss vor dem bisherigen Ende der Zuordnung liegen.');
        }
        return new AssignmentPeriod($assignment->period->validFrom, AssignmentPeriod::nextDay($last));
    }

    /**
     * Darf die Zuordnung geloescht werden? Nur, wenn sie noch nie gegolten hat, ihr Beginn also in der Zukunft liegt.
     *
     * @throws CodedException `assignment_applied` (409)
     */
    public function assertDeletable(Assignment $assignment): void
    {
        if ($assignment->period->startedBy($this->clock->today())) {
            throw new CodedException('assignment_applied', 409, 'Die Zuordnung hat schon gegolten und lässt sich nicht löschen. Bitte stattdessen beenden.');
        }
    }

    /** `upcoming` (Beginn in der Zukunft), `ended` (letzter Tag vor heute) oder `running`. */
    public function status(SchoolYear $year, AssignmentPeriod $period): string
    {
        $today = $this->clock->today();
        return match (true) {
            $period->validFrom > $today => self::STATUS_UPCOMING,
            $period->lastDay($year) < $today => self::STATUS_ENDED,
            default => self::STATUS_RUNNING,
        };
    }
}
