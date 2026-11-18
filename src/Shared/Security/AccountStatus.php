<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

/** Status eines Kontos (ADR 0002 B1): eingeladen bis zur ersten Anmeldung, dann aktiv, sonst deaktiviert. */
enum AccountStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Inactive = 'inactive';
}
