<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Clock;

/** Eine Uhr mit festem Tag, fuer Tests mit festem Datum. */
final class FixedClock implements Clock
{
    public function __construct(private readonly string $today)
    {
        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $today)?->format('Y-m-d') !== $today) {
            throw new \InvalidArgumentException('Der Tag der Uhr muss ein Kalendertag im Format JJJJ-MM-TT sein.');
        }
    }

    public function today(): string
    {
        return $this->today;
    }
}
