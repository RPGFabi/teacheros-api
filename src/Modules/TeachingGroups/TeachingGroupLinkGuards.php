<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Shared\Extension\LinkGuardRegistry;

/**
 * Erweiterungspunkt der Fachgruppen: sammelt die {@see TeachingGroupLinkGuard}s der Module (Grundlage {@see LinkGuardRegistry},
 * gleiches Muster wie `SubjectLinkGuards`). Ohne Anmeldung gilt eine Fachgruppe als unverknuepft.
 */
final class TeachingGroupLinkGuards extends LinkGuardRegistry
{
    public function register(TeachingGroupLinkGuard $guard): void
    {
        $this->add($guard);
    }

    protected function errorCode(): string
    {
        return 'teaching_group_linked';
    }

    protected function subject(): string
    {
        return 'Die Fachgruppe';
    }
}
