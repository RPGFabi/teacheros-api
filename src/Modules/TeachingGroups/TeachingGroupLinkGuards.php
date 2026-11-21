<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Extension\GuardRegistry;

/**
 * Erweiterungspunkt der Fachgruppen: sammelt die {@see TeachingGroupLinkGuard}s der Module (gleiches Muster wie
 * `SubjectLinkGuards`). Ohne Anmeldung gilt eine Fachgruppe als unverknuepft.
 */
final class TeachingGroupLinkGuards extends GuardRegistry
{
    public function register(TeachingGroupLinkGuard $guard): void
    {
        $this->add($guard);
    }

    /**
     * Bezeichnungen aller Verknuepfungen, die an der Fachgruppe haengen; leer, wenn sie nirgends verknuepft ist.
     *
     * @return list<string>
     */
    public function linksOf(string $teachingGroupId): array
    {
        $labels = [];
        foreach ($this->guards() as $guard) {
            if ($guard->isLinked($teachingGroupId)) {
                $labels[] = $guard->label();
            }
        }
        return $labels;
    }

    /** @throws CodedException `teaching_group_linked` (409), die Meldung nennt alle Verknuepfungen */
    public function assertDeletable(string $teachingGroupId): void
    {
        $links = $this->linksOf($teachingGroupId);
        if ($links !== []) {
            throw new CodedException('teaching_group_linked', 409, 'Die Fachgruppe ist verknüpft (' . implode(', ', $links) . ') und kann nicht gelöscht werden. Bitte stattdessen archivieren.');
        }
    }
}
