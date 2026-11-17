<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Exceptions;

use Hoelzle\Core\Exceptions\ApiException;

/**
 * Fachlicher Fehler mit maschinenlesbarem Code (z. B. `username_taken`, `already_active`)
 * und HTTP-Status. Der Code ist fuer Clients und die CLI gedacht, die Nachricht fuer Menschen.
 */
class CodedException extends ApiException
{
    public function __construct(public readonly string $errorCode, int $httpCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode, $httpCode);
    }
}
