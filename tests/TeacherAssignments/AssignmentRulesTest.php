<?php

declare(strict_types=1);

namespace TeacherOS\Tests\TeacherAssignments;

use PHPUnit\Framework\TestCase;
use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYearStatus;
use TeacherOS\Modules\TeacherAssignments\Assignment;
use TeacherOS\Modules\TeacherAssignments\AssignmentPeriod;
use TeacherOS\Modules\TeacherAssignments\AssignmentRules;
use TeacherOS\Shared\Clock\FixedClock;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die Zuordnungsregeln (Ticket #67, GLOSSARY.md: Lehrkraft-Zuordnung, Gueltigkeitszeitraum) als reiner Baustein ohne
 * Datenbank, mit festem "heute" (2026-11-21). Dieselben Regeln gelten fuer Klassenleitungen (#68).
 * Schuljahr im Test: 2026-09-14 bis 2027-07-29 (einschliesslich).
 */
final class AssignmentRulesTest extends TestCase
{
    private SchoolYear $year;
    private AssignmentRules $rules;

    protected function setUp(): void
    {
        $this->year = new SchoolYear('y1', '2026/27', '2026-09-14', '2027-07-29', SchoolYearStatus::Running);
        $this->rules = new AssignmentRules(new FixedClock('2026-11-21'));
    }

    private function assignment(string $id, string $teacher, string $from, ?string $until): Assignment
    {
        return new Assignment($id, $teacher, new AssignmentPeriod($from, $until));
    }

    private function assertRejected(string $code, int $status, \Closure $call): void
    {
        try {
            $call();
            $this->fail("Erwartet $code ($status)");
        } catch (CodedException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->getHttpCode());
        }
    }

    // --- Zeitraum ---

    public function testOhneAngabenBeginntDerZeitraumAmSchuljahresanfangUndIstOffen(): void
    {
        $period = $this->rules->period($this->year, null, null);

        $this->assertSame('2026-09-14', $period->validFrom);
        $this->assertNull($period->validUntil);
        $this->assertSame('2027-07-29', $period->lastDay($this->year));
    }

    public function testDasEndeIstEinschliesslichUndWirdAlsExklusiveObergrenzeGespeichert(): void
    {
        $period = $this->rules->period($this->year, '2026-10-01', '2026-12-31');

        $this->assertSame('2026-10-01', $period->validFrom);
        $this->assertSame('2027-01-01', $period->validUntil);
        $this->assertSame('2026-12-31', $period->lastDay($this->year));
    }

    public function testEinEndeAmLetztenSchuljahrestagBleibtEinFestesEnde(): void
    {
        $period = $this->rules->period($this->year, null, '2027-07-29');

        $this->assertSame('2027-07-30', $period->validUntil);
    }

    public function testEinTagZeitraumIstErlaubt(): void
    {
        $period = $this->rules->period($this->year, '2026-10-01', '2026-10-01');

        $this->assertSame('2026-10-02', $period->validUntil);
    }

    public function testBeginnOderEndeAusserhalbDesSchuljahresIst422(): void
    {
        foreach ([['2026-09-13', null], ['2027-07-30', null], [null, '2027-07-30'], ['2026-10-01', '2026-09-13']] as [$from, $until]) {
            $this->assertRejected('period_outside_school_year', 422, fn () => $this->rules->period($this->year, $from, $until));
        }
    }

    public function testEndeVorDemBeginnIst422(): void
    {
        $this->assertRejected('invalid_period', 422, fn () => $this->rules->period($this->year, '2026-12-01', '2026-11-30'));
    }

    public function testKeinEchterKalendertagIst400(): void
    {
        foreach (['2026-02-30', '01.10.2026', '', 5, ['x']] as $bad) {
            $this->assertRejected('invalid_request', 400, fn () => $this->rules->period($this->year, $bad, null));
            $this->assertRejected('invalid_request', 400, fn () => $this->rules->period($this->year, null, $bad));
        }
    }

    // --- Neue Zuordnung: Ueberschneidung und Obergrenze ---

    public function testDieselbeLehrkraftNichtMitUeberschneidendemZeitraum(): void
    {
        $existing = [$this->assignment('a1', 't1', '2026-09-14', '2026-11-01')];
        $period = new AssignmentPeriod('2026-10-31', null);

        $this->assertRejected('assignment_overlap', 409, fn () => $this->rules->assertCanAdd($this->year, $period, 't1', $existing, 5));
    }

    public function testNacheinanderIstErlaubtAuchLueckenlos(): void
    {
        $existing = [$this->assignment('a1', 't1', '2026-09-14', '2026-11-01')];

        $this->rules->assertCanAdd($this->year, new AssignmentPeriod('2026-11-01', null), 't1', $existing, 1);
        $this->addToAssertionCount(1);
    }

    public function testEineOffeneZuordnungReichtBisZumSchuljahresende(): void
    {
        $existing = [$this->assignment('a1', 't1', '2026-09-14', null)];

        $this->assertRejected('assignment_overlap', 409, fn () => $this->rules->assertCanAdd($this->year, new AssignmentPeriod('2027-07-29', null), 't1', $existing, 5));
    }

    public function testAndereLehrkraeftDuerfenZugleichBisZurObergrenze(): void
    {
        $existing = [$this->assignment('a1', 't1', '2026-09-14', null), $this->assignment('a2', 't2', '2026-09-14', null)];
        $period = new AssignmentPeriod('2026-09-14', null);

        $this->rules->assertCanAdd($this->year, $period, 't3', $existing, 3);
        $this->assertRejected('assignment_limit_reached', 409, fn () => $this->rules->assertCanAdd($this->year, $period, 't3', $existing, 2));
    }

    public function testDieObergrenzeGiltAnJedemTagNichtNurAmBeginn(): void
    {
        // t1 und t2 ueberlappen erst im Dezember; die neue Zuordnung reicht das ganze Jahr: im Dezember waeren es drei.
        $existing = [$this->assignment('a1', 't1', '2026-09-14', '2027-01-01'), $this->assignment('a2', 't2', '2026-12-01', null)];
        $period = new AssignmentPeriod('2026-09-14', null);

        $this->assertRejected('assignment_limit_reached', 409, fn () => $this->rules->assertCanAdd($this->year, $period, 't3', $existing, 2));
    }

    public function testNacheinanderGelegeneZuordnungenZaehlenNichtZusammen(): void
    {
        $existing = [$this->assignment('a1', 't1', '2026-09-14', '2026-12-01'), $this->assignment('a2', 't2', '2026-12-01', null)];

        $this->rules->assertCanAdd($this->year, new AssignmentPeriod('2026-09-14', null), 't3', $existing, 2);
        $this->addToAssertionCount(1);
    }

    public function testBestehendesUeberDerObergrenzeBleibtUnberuehrtNurNeuesWirdAbgelehnt(): void
    {
        // Die Schule hat auf 1 verschaerft, bestehend sind zwei: eine Zuordnung ausserhalb dieser Tage ist weiter moeglich.
        $existing = [$this->assignment('a1', 't1', '2026-09-14', '2026-10-01'), $this->assignment('a2', 't2', '2026-09-14', '2026-10-01')];

        $this->rules->assertCanAdd($this->year, new AssignmentPeriod('2026-10-01', null), 't3', $existing, 1);
        $this->assertRejected('assignment_limit_reached', 409, fn () => $this->rules->assertCanAdd($this->year, new AssignmentPeriod('2026-09-30', null), 't3', $existing, 1));
    }

    // --- Beenden ---

    public function testEineOffeneZuordnungBeendenSetztDasEndeEinschliesslich(): void
    {
        $open = $this->assignment('a1', 't1', '2026-09-14', null);

        $ended = $this->rules->ended($this->year, $open, '2026-12-18');

        $this->assertSame('2026-09-14', $ended->validFrom);
        $this->assertSame('2026-12-19', $ended->validUntil);
    }

    public function testOhneAngabeWirdHeuteDerLetzteTag(): void
    {
        $ended = $this->rules->ended($this->year, $this->assignment('a1', 't1', '2026-09-14', null), null);

        $this->assertSame('2026-11-22', $ended->validUntil);
    }

    public function testEinInDerZukunftLiegendesEndeLaesstSichVorverlegen(): void
    {
        $ended = $this->rules->ended($this->year, $this->assignment('a1', 't1', '2026-09-14', '2027-03-01'), '2026-12-18');

        $this->assertSame('2026-12-19', $ended->validUntil);
    }

    public function testRueckwirkendBeendenIst409WeilEsSchonGegoltenHat(): void
    {
        $this->assertRejected('assignment_applied', 409, fn () => $this->rules->ended($this->year, $this->assignment('a1', 't1', '2026-09-14', null), '2026-11-20'));
    }

    public function testEineBereitsBeendeteZuordnungLaesstSichNichtMehrAendern(): void
    {
        $this->assertRejected('assignment_ended', 409, fn () => $this->rules->ended($this->year, $this->assignment('a1', 't1', '2026-09-14', '2026-11-21'), '2026-11-21'));
    }

    public function testDasEndeAmHeutigenTagIstNochMoeglich(): void
    {
        $ended = $this->rules->ended($this->year, $this->assignment('a1', 't1', '2026-09-14', '2026-12-01'), '2026-11-21');

        $this->assertSame('2026-11-22', $ended->validUntil);
    }

    public function testDasNeueEndeMussVorDemBisherigenEndeLiegen(): void
    {
        $this->assertRejected('invalid_period', 422, fn () => $this->rules->ended($this->year, $this->assignment('a1', 't1', '2026-09-14', '2026-12-01'), '2026-12-05'));
        $this->assertRejected('invalid_period', 422, fn () => $this->rules->ended($this->year, $this->assignment('a1', 't1', '2026-09-14', null), '2027-07-29'));
    }

    public function testEineErstSpaeterBeginnendeZuordnungLaesstSichNurAbBeginnBeenden(): void
    {
        $future = $this->assignment('a1', 't1', '2027-01-11', null);

        $this->assertRejected('invalid_period', 422, fn () => $this->rules->ended($this->year, $future, '2027-01-10'));
        $this->assertSame('2027-01-12', $this->rules->ended($this->year, $future, '2027-01-11')->validUntil);
    }

    // --- Loeschen ---

    public function testNurWasNochNieGegoltenHatDarfGeloeschtWerden(): void
    {
        $this->rules->assertDeletable($this->assignment('a1', 't1', '2026-11-22', null));
        $this->addToAssertionCount(1);

        foreach (['2026-11-21', '2026-09-14'] as $from) {
            $this->assertRejected('assignment_applied', 409, fn () => $this->rules->assertDeletable($this->assignment('a1', 't1', $from, null)));
        }
    }

    // --- Status ---

    public function testStatusFolgtAusDemHeutigenDatum(): void
    {
        $this->assertSame('upcoming', $this->rules->status($this->year, new AssignmentPeriod('2026-11-22', null)));
        $this->assertSame('running', $this->rules->status($this->year, new AssignmentPeriod('2026-11-21', null)));
        $this->assertSame('running', $this->rules->status($this->year, new AssignmentPeriod('2026-09-14', '2026-11-22')));
        $this->assertSame('ended', $this->rules->status($this->year, new AssignmentPeriod('2026-09-14', '2026-11-21')));
    }

    // --- Schuljahr aendern ---

    public function testEinNeuerSchuljahreszeitraumMussAlleZeitraeumeUmfassen(): void
    {
        $fixed = new AssignmentPeriod('2026-10-01', '2026-12-21');
        $open = new AssignmentPeriod('2026-10-01', null);

        $this->assertTrue($fixed->fitsWithin('2026-09-14', '2027-07-29'));
        $this->assertFalse($fixed->fitsWithin('2026-10-02', '2027-07-29'), 'Beginn faellt heraus');
        $this->assertFalse($fixed->fitsWithin('2026-09-14', '2026-12-19'), 'Ende faellt heraus');
        $this->assertTrue($open->fitsWithin('2026-09-14', '2026-12-18'), 'ein offenes Ende folgt dem Schuljahresende');
        $this->assertFalse($open->fitsWithin('2026-09-14', '2026-09-30'), 'Beginn nach dem neuen Schuljahresende');
    }
}
