<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

/**
 * Ein offener Aufruf an den Anmeldedienst (ADR 0002 Nachtrag #16, Ticket #47): eine lokal schon festgeschriebene
 * Wirkung (Sperre, Sitzungswiderruf, TOTP-Zuruecksetzung), die der Dienst noch nicht bestaetigt hat. Das Konto traegt eine
 * Menge davon, je Aufruf hoechstens einen.
 */
final class OpenCall
{
    /**
     * @param string $operation Name des Aufrufs, z. B. `disable_login` ({@see OpenCallHandler::operation()})
     * @param string|null $rejectionCode Code, mit dem der Anmeldedienst den Aufruf zuletzt fachlich abgelehnt hat; null, solange er nur nicht erreichbar war oder noch nicht versucht wurde
     */
    public function __construct(public readonly string $operation, public readonly ?string $rejectionCode = null)
    {
    }
}
