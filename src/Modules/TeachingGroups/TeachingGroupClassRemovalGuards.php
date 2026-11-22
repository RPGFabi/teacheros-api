<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Extension\GuardRegistry;

/**
 * Erweiterungspunkt der Fachgruppen gegen das Entfernen einer Klasse: sammelt die {@see TeachingGroupClassRemovalGuard}s der
 * Module und fragt sie in der Reihenfolge der Anmeldung; der erste Einwand gewinnt. Ohne Anmeldung hat niemand etwas
 * einzuwenden.
 */
final class TeachingGroupClassRemovalGuards extends GuardRegistry
{
    public function register(TeachingGroupClassRemovalGuard $guard): void
    {
        $this->add($guard);
    }

    /** @throws CodedException 409 mit Code und Meldung des ersten Einwands */
    public function assertRemovable(string $teachingGroupId, string $classId): void
    {
        foreach ($this->guards() as $guard) {
            $objection = $guard->objectionToRemoval($teachingGroupId, $classId);
            if ($objection !== null) {
                throw $objection->exception();
            }
        }
    }
}
