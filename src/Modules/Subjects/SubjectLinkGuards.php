<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Extension\GuardRegistry;

/**
 * Erweiterungspunkt der Faecher: sammelt die {@see SubjectLinkGuard}s der Module (gleiches Muster wie `ClassLinkGuards`).
 * Ohne Anmeldung gilt ein Fach als unverknuepft.
 */
final class SubjectLinkGuards extends GuardRegistry
{
    public function register(SubjectLinkGuard $guard): void
    {
        $this->add($guard);
    }

    /**
     * Bezeichnungen aller Verknuepfungen, die am Fach haengen; leer, wenn es nirgends verknuepft ist.
     *
     * @return list<string>
     */
    public function linksOf(string $subjectId): array
    {
        $labels = [];
        foreach ($this->guards() as $guard) {
            if ($guard->isLinked($subjectId)) {
                $labels[] = $guard->label();
            }
        }
        return $labels;
    }

    /** @throws CodedException `subject_linked` (409), die Meldung nennt alle Verknuepfungen */
    public function assertDeletable(string $subjectId): void
    {
        $links = $this->linksOf($subjectId);
        if ($links !== []) {
            throw new CodedException('subject_linked', 409, 'Das Fach ist verknüpft (' . implode(', ', $links) . ') und kann nicht gelöscht werden. Bitte stattdessen archivieren.');
        }
    }
}
