<?php

declare(strict_types=1);

namespace TeacherOS\Tests\SchoolYears;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Modules\SchoolYears\SchoolYearPeriod;
use TeacherOS\Shared\Exceptions\CodedException;

/** Zeitraum und Bezeichnung des Schuljahres (Ticket #54): Kalendertage, Ende im Folgejahr, Bezeichnung "JJJJ/JJ". */
final class SchoolYearPeriodTest extends TestCase
{
    public function testBezeichnungAusJahrDesBeginnsUndLetztenZweiZiffernDesEndjahres(): void
    {
        $period = SchoolYearPeriod::fromStrings('2026-09-14', '2027-07-29');

        $this->assertSame('2026/27', $period->name());
        $this->assertSame('2026-09-14', $period->startsOn);
        $this->assertSame('2027-07-29', $period->endsOn);
    }

    public function testBezeichnungBehaeltFuehrendeNullBeimJahrhundertwechsel(): void
    {
        $this->assertSame('2099/00', SchoolYearPeriod::fromStrings('2099-08-01', '2100-07-31')->name());
        $this->assertSame('2008/09', SchoolYearPeriod::fromStrings('2008-08-01', '2009-07-31')->name());
    }

    /** @return iterable<string,array{string,string}> */
    public static function gueltigeGrenzfaelle(): iterable
    {
        yield 'Ende am letzten Tag des Folgejahres' => ['2026-09-14', '2027-12-31'];
        yield 'Beginn im Januar, Ende im Dezember des Folgejahres' => ['2026-01-01', '2027-12-31'];
        yield 'Beginn im Dezember, Ende am ersten Tag des Folgejahres' => ['2026-12-31', '2027-01-01'];
        yield 'Schaltjahr' => ['2027-08-01', '2028-02-29'];
    }

    #[DataProvider('gueltigeGrenzfaelle')]
    public function testEndeImKalenderjahrNachDemBeginnIstGueltig(string $start, string $end): void
    {
        $this->assertSame($end, SchoolYearPeriod::fromStrings($start, $end)->endsOn);
    }

    /** @return iterable<string,array{string,string}> */
    public static function ungueltigeZeitraeume(): iterable
    {
        yield 'Ende im selben Kalenderjahr' => ['2026-01-10', '2026-12-31'];
        yield 'Ende vor dem Beginn' => ['2026-09-14', '2026-07-29'];
        yield 'Ende zwei Kalenderjahre spaeter' => ['2026-09-14', '2028-01-01'];
        yield 'Ende im Vorjahr' => ['2026-09-14', '2025-07-29'];
    }

    #[DataProvider('ungueltigeZeitraeume')]
    public function testEndeAusserhalbDesFolgejahresWirdMit422InvalidPeriodAbgelehnt(string $start, string $end): void
    {
        try {
            SchoolYearPeriod::fromStrings($start, $end);
            $this->fail('Erwartet: invalid_period');
        } catch (CodedException $e) {
            $this->assertSame('invalid_period', $e->errorCode);
            $this->assertSame(422, $e->getHttpCode());
        }
    }

    /** @return iterable<string,array{mixed,mixed}> */
    public static function ungueltigeDaten(): iterable
    {
        yield 'kein Datum' => ['morgen', '2027-07-29'];
        yield 'deutsches Format' => ['14.09.2026', '29.07.2027'];
        yield 'Tag gibt es nicht' => ['2026-02-30', '2027-07-29'];
        yield 'Datum mit Uhrzeit' => ['2026-09-14T10:00:00', '2027-07-29'];
        yield 'fehlt' => [null, '2027-07-29'];
        yield 'Ende fehlt' => ['2026-09-14', null];
        yield 'Zahl' => [20260914, '2027-07-29'];
        yield 'leer' => ['', ''];
    }

    #[DataProvider('ungueltigeDaten')]
    public function testKeinGueltigerKalendertagWirdMit400InvalidRequestAbgelehnt(mixed $start, mixed $end): void
    {
        try {
            SchoolYearPeriod::fromInput($start, $end);
            $this->fail('Erwartet: invalid_request');
        } catch (CodedException $e) {
            $this->assertSame('invalid_request', $e->errorCode);
            $this->assertSame(400, $e->getHttpCode());
        }
    }
}
