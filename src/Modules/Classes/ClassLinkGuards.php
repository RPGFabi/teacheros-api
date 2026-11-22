<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use TeacherOS\Shared\Extension\LinkGuardRegistry;

/**
 * Erweiterungspunkt der Klassen: sammelt die {@see ClassLinkGuard}s der Module (Grundlage {@see LinkGuardRegistry}, gleiches
 * Muster wie `SchoolYearGuards`). Ohne Anmeldung gilt eine Klasse als unverknuepft.
 */
final class ClassLinkGuards extends LinkGuardRegistry
{
    public function register(ClassLinkGuard $guard): void
    {
        $this->add($guard);
    }

    protected function errorCode(): string
    {
        return 'class_linked';
    }

    protected function subject(): string
    {
        return 'Die Klasse';
    }
}
