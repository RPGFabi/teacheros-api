<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

/**
 * Schutzstufe einer Rolle, gespeichert in `role_settings` (ADR 0002 B4, Nachtrag #11).
 *
 * Bearbeiten und Loeschen (Folgetickets) richten sich danach: `Wildcard` und `Protected` sind weder
 * bearbeitbar noch loeschbar, `Standard` ist bearbeitbar, aber nicht loeschbar (Verstoss: 409 `role_protected`),
 * `Custom` ist beides. "Erfordert MFA" ist nur bei `Custom` aenderbar.
 */
enum RoleProtection: string
{
    case Wildcard = 'wildcard';
    case Protected = 'protected';
    case Standard = 'standard';
    case Custom = 'custom';
}
