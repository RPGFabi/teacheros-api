<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Classes;

use PHPUnit\Framework\TestCase;
use TeacherOS\Modules\Classes\SchoolClass;
use TeacherOS\Modules\SchoolYears\SchoolYearRef;

/** Die Ausgabe einer Klasse (Ticket #69): die JSON-Form der API bleibt, wie sie die Web-Seite liest. */
final class SchoolClassTest extends TestCase
{
    public function testJsonFormTraegtStufeBezeichnungArchivierungUndSchuljahr(): void
    {
        $class = new SchoolClass('c-1', 7, '7a', true, new SchoolYearRef('sy-1', '2026/27'));

        $this->assertSame(
            ['id' => 'c-1', 'grade_level' => 7, 'name' => '7a', 'archived' => true, 'school_year' => ['id' => 'sy-1', 'name' => '2026/27']],
            $class->toArray(),
        );
    }
}
