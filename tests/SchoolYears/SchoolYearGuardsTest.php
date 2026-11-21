<?php

declare(strict_types=1);

namespace TeacherOS\Tests\SchoolYears;

use PHPUnit\Framework\TestCase;
use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYearGuard;
use TeacherOS\Modules\SchoolYears\SchoolYearGuards;
use TeacherOS\Modules\SchoolYears\SchoolYearObjection;
use TeacherOS\Modules\SchoolYears\SchoolYearStatus;
use TeacherOS\Modules\SchoolYears\SchoolYearSpan;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Extension\ExtensionPoints;

/**
 * Der Erweiterungspunkt der Schuljahre (Ticket #56) als Instanz, ohne Datenbank und ohne HTTP: Reihenfolge, erster Einwand
 * gewinnt, eigene Instanz je Test statt globalem Zustand.
 */
final class SchoolYearGuardsTest extends TestCase
{
    /** @param list<array<int,string>> $asked fuellt sich mit den Fragen, die der Waechter bekommt */
    private static function guard(?SchoolYearObjection $update, ?SchoolYearObjection $deletion, array &$asked = []): SchoolYearGuard
    {
        return new class ($update, $deletion, $asked) implements SchoolYearGuard {
            /** @param list<array<int,string>> $asked */
            public function __construct(private readonly ?SchoolYearObjection $update, private readonly ?SchoolYearObjection $deletion, private array &$asked)
            {
            }

            public function objectionToUpdate(SchoolYear $year, SchoolYearSpan $newSpan): ?SchoolYearObjection
            {
                $this->asked[] = ['update', $year->id, $newSpan->name()];
                return $this->update;
            }

            public function objectionToDeletion(SchoolYear $year): ?SchoolYearObjection
            {
                $this->asked[] = ['delete', $year->id];
                return $this->deletion;
            }
        };
    }

    private static function year(): SchoolYear
    {
        return new SchoolYear('sy-1', '2026/27', '2026-09-14', '2027-07-29', SchoolYearStatus::Upcoming);
    }

    private static function span(string $start = '2026-09-14', string $end = '2027-07-29'): SchoolYearSpan
    {
        return SchoolYearSpan::fromStrings($start, $end);
    }

    /** Code und Meldung der Ablehnung (409) oder null, wenn der Aufruf durchlaeuft. */
    private function rejection(\Closure $call): ?string
    {
        try {
            $call();
        } catch (CodedException $e) {
            $this->assertSame(409, $e->getHttpCode());
            return $e->errorCode . '|' . $e->getMessage();
        }
        return null;
    }

    public function testOhneAnmeldungHatNiemandEtwasEinzuwenden(): void
    {
        $guards = new SchoolYearGuards();

        $this->assertNull($this->rejection(fn () => $guards->assertUpdatable(self::year(), self::span())));
        $this->assertNull($this->rejection(fn () => $guards->assertDeletable(self::year())));
    }

    public function testEinEinwandWirdZu409MitCodeUndMeldung(): void
    {
        $guards = new SchoolYearGuards();
        $guards->register(self::guard(new SchoolYearObjection('a_update', 'Meldung A'), new SchoolYearObjection('a_delete', 'Meldung B')));

        $this->assertSame('a_update|Meldung A', $this->rejection(fn () => $guards->assertUpdatable(self::year(), self::span('2026-09-15'))));
        $this->assertSame('a_delete|Meldung B', $this->rejection(fn () => $guards->assertDeletable(self::year())));
    }

    public function testDerErsteEinwandInDerReihenfolgeDerAnmeldungGewinntUndDanachWirdNichtMehrGefragt(): void
    {
        $askedFirst = $askedSecond = $askedThird = [];
        $guards = new SchoolYearGuards();
        $guards->register(self::guard(null, null, $askedFirst));
        $guards->register(self::guard(new SchoolYearObjection('zweiter', 'Z'), new SchoolYearObjection('zweiter_del', 'Z'), $askedSecond));
        $guards->register(self::guard(new SchoolYearObjection('dritter', 'D'), new SchoolYearObjection('dritter_del', 'D'), $askedThird));

        $this->assertSame('zweiter|Z', $this->rejection(fn () => $guards->assertUpdatable(self::year(), self::span())));
        $this->assertSame('zweiter_del|Z', $this->rejection(fn () => $guards->assertDeletable(self::year())));
        $this->assertCount(2, $askedFirst, 'der erste Waechter wird gefragt');
        $this->assertCount(2, $askedSecond);
        $this->assertSame([], $askedThird, 'nach dem ersten Einwand fragt niemand weiter');
    }

    public function testDieWaechterBekommenDasSchuljahrUndDenNeuenZeitraum(): void
    {
        $asked = [];
        $guards = new SchoolYearGuards();
        $guards->register(self::guard(null, null, $asked));

        $guards->assertUpdatable(self::year(), self::span('2027-09-01', '2028-07-31'));

        $this->assertSame([['update', 'sy-1', '2027/28']], $asked);
    }

    public function testErweiterungspunkteSindProInstanzUndKeinGlobalerZustand(): void
    {
        $one = new ExtensionPoints();
        $other = new ExtensionPoints();
        $one->get(SchoolYearGuards::class)->register(self::guard(new SchoolYearObjection('x', 'X'), null));

        $this->assertSame($one->get(SchoolYearGuards::class), $one->get(SchoolYearGuards::class), 'je Anwendung eine Instanz');
        $this->assertSame('x|X', $this->rejection(fn () => $one->get(SchoolYearGuards::class)->assertUpdatable(self::year(), self::span())));
        $this->assertNull($this->rejection(fn () => $other->get(SchoolYearGuards::class)->assertUpdatable(self::year(), self::span())), 'eine andere Anwendung sieht die Anmeldung nicht');
    }
}
