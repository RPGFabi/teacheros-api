<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolYears;

use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Extension\GuardRegistry;

/**
 * Erweiterungspunkt der Schuljahre: sammelt die {@see SchoolYearGuard}s der Module und fragt sie in der Reihenfolge der
 * Anmeldung. Der erste Einwand gewinnt. Ein Modul meldet sich in seiner Modul-Registrierung an
 * (`$points->get(SchoolYearGuards::class)->register(...)`, siehe {@see \TeacherOS\Shared\Extension\ExtensionPoints}); ohne
 * Anmeldung hat niemand etwas einzuwenden.
 */
final class SchoolYearGuards extends GuardRegistry
{
    public function register(SchoolYearGuard $guard): void
    {
        $this->add($guard);
    }

    /**
     * @param SchoolYear $year das Schuljahr vor der Aenderung
     * @throws CodedException 409 mit Code und Meldung des ersten Einwands
     */
    public function assertUpdatable(SchoolYear $year, SchoolYearSpan $newSpan): void
    {
        $this->assert(static fn (SchoolYearGuard $guard): ?SchoolYearObjection => $guard->objectionToUpdate($year, $newSpan));
    }

    /**
     * @param SchoolYear $year
     * @throws CodedException 409 mit Code und Meldung des ersten Einwands
     */
    public function assertDeletable(SchoolYear $year): void
    {
        $this->assert(static fn (SchoolYearGuard $guard): ?SchoolYearObjection => $guard->objectionToDeletion($year));
    }

    /** @param \Closure(SchoolYearGuard):?SchoolYearObjection $ask */
    private function assert(\Closure $ask): void
    {
        foreach ($this->guards() as $guard) {
            $objection = $ask($guard);
            if ($objection !== null) {
                throw $objection->exception();
            }
        }
    }
}
