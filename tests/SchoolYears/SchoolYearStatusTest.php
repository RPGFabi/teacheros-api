<?php

declare(strict_types=1);

namespace TeacherOS\Tests\SchoolYears;

use PHPUnit\Framework\TestCase;
use TeacherOS\Modules\SchoolYears\SchoolYearStatus;

/**
 * Status der Schuljahre (Ticket #54): wird aus dem heutigen Datum abgeleitet und nie gespeichert.
 * laufend = heute im Zeitraum (beide Grenzen inklusive) oder heute in einer Luecke und das naechste kommende Schuljahr.
 */
final class SchoolYearStatusTest extends TestCase
{
    private const Y2425 = ['2024-09-02', '2025-07-31'];
    private const Y2526 = ['2025-09-01', '2026-07-31'];
    private const Y2627 = ['2026-09-14', '2027-07-29'];

    /**
     * @param list<array{string,string}> $years Zeitraeume (Beginn, Ende)
     * @return list<string> Statuswerte in der Reihenfolge der Zeitraeume
     */
    private function statuses(string $today, array $years): array
    {
        return array_map(
            static fn (SchoolYearStatus $s): string => $s->value,
            SchoolYearStatus::derive($years, new \DateTimeImmutable($today)),
        );
    }

    public function testHeuteImZeitraumIstLaufendVorherVergangenNachherKommend(): void
    {
        $this->assertSame(
            ['past', 'running', 'upcoming'],
            $this->statuses('2025-11-19', [self::Y2425, self::Y2526, self::Y2627]),
        );
    }

    public function testBeginnUndEndeSindInklusive(): void
    {
        $this->assertSame(['running'], $this->statuses('2025-09-01', [self::Y2526]));
        $this->assertSame(['running'], $this->statuses('2026-07-31', [self::Y2526]));
    }

    public function testHeuteInEinerLueckeIstDasNaechsteKommendeLaufendUndDieFolgendenKommend(): void
    {
        $this->assertSame(
            ['past', 'past', 'running'],
            $this->statuses('2026-08-15', [self::Y2425, self::Y2526, self::Y2627]),
        );
        $this->assertSame(
            ['past', 'running', 'upcoming'],
            $this->statuses('2025-08-15', [self::Y2425, self::Y2526, self::Y2627]),
        );
    }

    public function testNachDemLetztenSchuljahrIstAllesVergangenUndNichtsLaufend(): void
    {
        $this->assertSame(['past', 'past'], $this->statuses('2027-08-01', [self::Y2526, self::Y2627]));
    }

    public function testVorDemErstenSchuljahrIstDasErsteLaufendDieRestlichenKommend(): void
    {
        $this->assertSame(['running', 'upcoming'], $this->statuses('2025-01-01', [self::Y2526, self::Y2627]));
    }

    public function testHoechstensEinSchuljahrIstLaufend(): void
    {
        foreach (['2024-09-02', '2025-08-15', '2025-09-01', '2026-08-20', '2027-07-29', '2030-01-01'] as $today) {
            $running = array_filter($this->statuses($today, [self::Y2425, self::Y2526, self::Y2627]), static fn (string $s): bool => $s === 'running');
            $this->assertLessThanOrEqual(1, count($running), $today);
        }
    }

    public function testKeinSchuljahrErgibtEineLeereListe(): void
    {
        $this->assertSame([], $this->statuses('2026-01-01', []));
    }
}
