<?php

declare(strict_types=1);

namespace TeacherOS\Tests\SchoolYears;

use PHPUnit\Framework\TestCase;
use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYearStatus;

/** Die Ausgabe eines Schuljahres (Ticket #69): die JSON-Form der API bleibt, wie sie die Web-Seite liest. */
final class SchoolYearTest extends TestCase
{
    public function testJsonFormEnthaeltKennungBezeichnungZeitraumUndStatus(): void
    {
        $year = new SchoolYear('sy-1', '2026/27', '2026-09-14', '2027-07-29', SchoolYearStatus::Running);

        $this->assertSame(
            ['id' => 'sy-1', 'name' => '2026/27', 'starts_on' => '2026-09-14', 'ends_on' => '2027-07-29', 'status' => 'running'],
            $year->toArray(),
        );
    }

    public function testVerweisNenntNurKennungUndBezeichnung(): void
    {
        $year = new SchoolYear('sy-1', '2026/27', '2026-09-14', '2027-07-29', SchoolYearStatus::Past);

        $this->assertSame(['id' => 'sy-1', 'name' => '2026/27'], $year->ref()->toArray());
    }
}
