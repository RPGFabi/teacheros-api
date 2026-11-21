<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Clock;

/** Die echte Uhr: "heute" ist der Kalendertag in der Ortszeit der Schule (Deutschland), wie bei den Schuljahren. */
final class SystemClock implements Clock
{
    private const TIMEZONE = 'Europe/Berlin';

    public function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
    }
}
