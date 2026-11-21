<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Extension\GuardRegistry;

/**
 * Erweiterungspunkt der Klassen: sammelt die {@see ClassLinkGuard}s der Module (gleiches Muster wie
 * `SchoolYearGuards`). Ohne Anmeldung gilt eine Klasse als unverknuepft.
 */
final class ClassLinkGuards extends GuardRegistry
{
    public function register(ClassLinkGuard $guard): void
    {
        $this->add($guard);
    }

    /**
     * Bezeichnungen aller Verknuepfungen, die an der Klasse haengen; leer, wenn sie nirgends verknuepft ist.
     *
     * @return list<string>
     */
    public function linksOf(string $classId): array
    {
        $labels = [];
        foreach ($this->guards() as $guard) {
            if ($guard->isLinked($classId)) {
                $labels[] = $guard->label();
            }
        }
        return $labels;
    }

    /** @throws CodedException `class_linked` (409), die Meldung nennt alle Verknuepfungen */
    public function assertDeletable(string $classId): void
    {
        $links = $this->linksOf($classId);
        if ($links !== []) {
            throw new CodedException('class_linked', 409, 'Die Klasse ist verknüpft (' . implode(', ', $links) . ') und kann nicht gelöscht werden. Bitte stattdessen archivieren.');
        }
    }
}
