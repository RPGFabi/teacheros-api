<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

/**
 * Anmeldung der Pruefungen ({@see SchoolYearGuard}). Die Anwendung meldet beim Start jede Pruefung an
 * (`AppBootstrap::registerRoutes`); {@see SchoolYears} fragt sie in der Reihenfolge der Anmeldung.
 */
final class SchoolYearGuards
{
    /** @var list<SchoolYearGuard> */
    private static array $guards = [];

    public static function register(SchoolYearGuard $guard): void
    {
        self::$guards[] = $guard;
    }

    /** @return list<SchoolYearGuard> */
    public static function all(): array
    {
        return self::$guards;
    }
}
