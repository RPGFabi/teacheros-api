<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Assignments;

use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Shared\Clock\Clock;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Regeln der Lehrkraft-Zuordnung (GLOSSARY.md: Lehrkraft-Zuordnung, Klassenleitung, Gueltigkeitszeitraum), unabhaengig
 * davon, woran die Zuordnung haengt: rein, ohne Datenbank, mit einer injizierten {@see Clock} fuer "heute". Der Dienst
 * {@see Assignments} liest die bestehenden Zuordnungen des Elternobjekts (Fachgruppe oder Klasse) als {@see Assignment}, fragt
 * hier und schreibt selbst. Alle Gueltigkeitszeitraeume liegen im Schuljahr des Elternobjekts, das als {@see SchoolYear}
 * mitgegeben wird.
 *
 * - {@see self::validity()}: Gueltigkeitszeitraum aus der Eingabe, immer innerhalb des Schuljahres
 * - {@see self::assertCanAdd()}: keine Ueberschneidung derselben Lehrkraft, an keinem Tag mehr als die Obergrenze
 * - {@see self::ended()}: Beenden, nie rueckwirkend, der Beginn bleibt
 * - {@see self::assertDeletable()}: Loeschen nur, was noch nie gegolten hat
 * - {@see self::status()}: kommend, laufend oder beendet
 * - {@see self::shorteningLosesValidDays()}: ein neues Schuljahresende, das bereits gegoltene Tage offener Zuordnungen kuerzte
 *
 * Fehlercodes sind allgemein gehalten (`assignment_*`, `*_validity`), damit sie fuer Fachgruppen und Klassen gleich lauten.
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
     * Der Gueltigkeitszeitraum aus der Eingabe: Beginn (ohne Angabe der erste Tag des Schuljahres) und Ende einschliesslich (ohne
     * Angabe offen, also bis zum Schuljahresende). Das Ende wird als exklusive Obergrenze gespeichert.
     *
     * @throws CodedException `invalid_request` (400), `validity_outside_school_year` (422), `invalid_validity` (422)
     */
    public function validity(SchoolYear $year, mixed $startsOn, mixed $endsOn): Validity
    {
        $from = $startsOn === null ? $year->startsOn : Validity::day($startsOn, 'den Beginn');
        $last = $endsOn === null ? null : Validity::day($endsOn, 'das Ende');
        foreach ([$from, $last] as $day) {
            if ($day !== null && ($day < $year->startsOn || $day > $year->endsOn)) {
                throw new CodedException('validity_outside_school_year', 422, 'Der Gültigkeitszeitraum muss innerhalb des Schuljahres (' . $year->name . ') liegen.');
            }
        }
        if ($last !== null && $last < $from) {
            throw new CodedException('invalid_validity', 422, 'Das Ende darf nicht vor dem Beginn liegen.');
        }
        return new Validity($from, $last === null ? null : Validity::nextDay($last));
    }

    /**
     * Darf eine Zuordnung mit diesem Gueltigkeitszeitraum fuer die Lehrkraft $teacherId neu hinzukommen? Dieselbe Lehrkraft nicht mit
     * sich ueberschneidendem Gueltigkeitszeitraum, nacheinander ist erlaubt; an keinem Tag des neuen Gueltigkeitszeitraums mehr als $max
     * Lehrkraefte zugleich. Bestehendes jenseits der Obergrenze (nach Verschaerfung der Schuleinstellung) bleibt
     * unberuehrt: nur die Tage des neuen Gueltigkeitszeitraums zaehlen.
     *
     * @param list<Assignment> $existing die bestehenden Zuordnungen desselben Elternobjekts (alle Lehrkraefte)
     * @throws CodedException `assignment_overlap` (409), `assignment_limit_reached` (409)
     */
    public function assertCanAdd(SchoolYear $year, Validity $validity, string $teacherId, array $existing, int $max): void
    {
        foreach ($existing as $other) {
            if ($other->teacherId === $teacherId && $other->validity->overlaps($validity, $year)) {
                throw new CodedException('assignment_overlap', 409, 'Die Lehrkraft ist an diesen Tagen schon zugeordnet. Gültigkeitszeiträume derselben Lehrkraft dürfen sich nicht überschneiden.');
            }
        }
        // Die Zahl gleichzeitiger Zuordnungen aendert sich nur an einem Beginn; es genuegt, den Beginn des neuen Gueltigkeitszeitraums
        // und jeden Beginn einer bestehenden Zuordnung darin zu pruefen.
        $days = [$validity->validFrom];
        foreach ($existing as $other) {
            if ($validity->coversDay($other->validity->validFrom, $year)) {
                $days[] = $other->validity->validFrom;
            }
        }
        foreach ($days as $day) {
            $simultaneous = count(array_filter($existing, static fn (Assignment $other): bool => $other->validity->coversDay($day, $year)));
            if ($simultaneous + 1 > $max) {
                throw new CodedException('assignment_limit_reached', 409, "An mindestens einem Tag des Gültigkeitszeitraums sind bereits {$simultaneous} Lehrkräfte zugleich zugeordnet; die Schule erlaubt höchstens {$max} (Schuleinstellung).");
            }
        }
    }

    /**
     * Der Gueltigkeitszeitraum nach dem Beenden: das Ende (einschliesslich, ohne Angabe heute) einer offenen Zuordnung wird gesetzt,
     * ein in der Zukunft liegendes Ende vorverlegt. Der Beginn bleibt, nie wird rueckwirkend geaendert: das neue Ende liegt
     * nicht vor heute (der heutige Tag zaehlt als schon gegolten) und nicht vor dem Beginn, und es liegt vor dem bisherigen Ende.
     *
     * @throws CodedException `assignment_ended` (409), `invalid_request` (400), `invalid_validity` (422), `assignment_applied` (409)
     */
    public function ended(SchoolYear $year, Assignment $assignment, mixed $endsOn): Validity
    {
        $today = $this->clock->today();
        $currentLast = $assignment->validity->lastDay($year);
        if ($currentLast < $today) {
            throw new CodedException('assignment_ended', 409, 'Die Zuordnung ist bereits beendet.');
        }
        $last = $endsOn === null ? $today : Validity::day($endsOn, 'das Ende');
        if ($last < $assignment->validity->validFrom) {
            throw new CodedException('invalid_validity', 422, 'Das Ende darf nicht vor dem Beginn der Zuordnung liegen.');
        }
        if ($last < $today) {
            throw new CodedException('assignment_applied', 409, 'Die Zuordnung hat bis heute schon gegolten und lässt sich nicht rückwirkend kürzen. Das Ende kann frühestens heute sein.');
        }
        if ($last >= $currentLast) {
            throw new CodedException('invalid_validity', 422, 'Das neue Ende muss vor dem bisherigen Ende der Zuordnung liegen.');
        }
        return new Validity($assignment->validity->validFrom, Validity::nextDay($last));
    }

    /**
     * Darf die Zuordnung geloescht werden? Nur, wenn sie noch nie gegolten hat, ihr Beginn also in der Zukunft liegt.
     *
     * @throws CodedException `assignment_applied` (409)
     */
    public function assertDeletable(Assignment $assignment): void
    {
        if ($assignment->validity->startedBy($this->clock->today())) {
            throw new CodedException('assignment_applied', 409, 'Die Zuordnung hat schon gegolten und lässt sich nicht löschen. Bitte stattdessen beenden.');
        }
    }

    /** `upcoming` (Beginn in der Zukunft), `ended` (letzter Tag vor heute) oder `running`. */
    public function status(SchoolYear $year, Validity $validity): string
    {
        $today = $this->clock->today();
        return match (true) {
            $validity->validFrom > $today => self::STATUS_UPCOMING,
            $validity->lastDay($year) < $today => self::STATUS_ENDED,
            default => self::STATUS_RUNNING,
        };
    }

    /**
     * Wuerde ein Schuljahr, das statt am bisherigen Tag $currentEndsOn schon am Tag $newEndsOn endet, offenen Zuordnungen, die
     * schon begonnen haben, bereits gegoltene Tage nehmen? Das ist der Fall, wenn das neue Ende vor heute und vor dem bisherigen
     * Ende liegt: eine offene Zuordnung gilt bis zum Schuljahresende, die Tage dazwischen haetten sie schon gegolten. Ein neues
     * Ende ab heute laesst alles Gegoltene stehen (der heutige Tag zaehlt als schon gegolten und bleibt im Schuljahr).
     */
    public function shorteningLosesValidDays(string $currentEndsOn, string $newEndsOn): bool
    {
        return $newEndsOn < $this->clock->today() && $newEndsOn < $currentEndsOn;
    }

    /** Der heutige Tag der Schule, fuer Abfragen, die die Uhr dieser Regeln brauchen. */
    public function today(): string
    {
        return $this->clock->today();
    }
}
