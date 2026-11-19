<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

/**
 * Warum ein Konto deaktiviert ist (ADR 0002 B2, Nachtrag #12). Die Werte sind die `reason`-Codes des Anmeldedienstes
 * (`/internal/users/disable-login`); V1 setzt nur die Sperre durch die Verwaltung.
 */
enum InactiveReason: string
{
    case AdminLocked = 'admin_locked';
}
