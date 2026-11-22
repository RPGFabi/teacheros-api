<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use TeacherOS\Shared\Extension\LinkGuardRegistry;

/**
 * Erweiterungspunkt der Faecher: sammelt die {@see SubjectLinkGuard}s der Module (Grundlage {@see LinkGuardRegistry}, gleiches
 * Muster wie `ClassLinkGuards`). Ohne Anmeldung gilt ein Fach als unverknuepft.
 */
final class SubjectLinkGuards extends LinkGuardRegistry
{
    public function register(SubjectLinkGuard $guard): void
    {
        $this->add($guard);
    }

    protected function errorCode(): string
    {
        return 'subject_linked';
    }

    protected function subject(): string
    {
        return 'Das Fach';
    }
}
