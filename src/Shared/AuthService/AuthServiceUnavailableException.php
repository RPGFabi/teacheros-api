<?php

declare(strict_types=1);

namespace TeacherOS\Shared\AuthService;

use TeacherOS\Shared\Exceptions\CodedException;

/** Der Anmeldedienst ist nicht erreichbar, zu langsam oder antwortet unerwartet (HTTP 502, `auth_unavailable`). */
final class AuthServiceUnavailableException extends CodedException
{
    public function __construct(string $reason)
    {
        parent::__construct('auth_unavailable', 502, 'Der Anmeldedienst ist nicht verfuegbar: ' . $reason);
    }
}
