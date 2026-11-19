<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

/**
 * Der Einwand eines Moduls gegen das Aendern oder Loeschen eines Schuljahres: maschinenlesbarer Code und Meldung fuer
 * Menschen. Der Aufrufer macht daraus `409` mit diesem Code ({@see SchoolYears}).
 */
final class SchoolYearObjection
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {
    }
}
