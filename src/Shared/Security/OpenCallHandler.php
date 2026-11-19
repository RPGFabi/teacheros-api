<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\AuthService\AuthServiceClient;

/**
 * Ein Aufruf an den Anmeldedienst, der als offener Aufruf nachgeholt werden kann (Ticket #47). Jede Art von Aufruf
 * (Sperre, Sitzungswiderruf, TOTP-Zuruecksetzung) ist ein Handler; {@see OpenCalls::standardHandlers()} fuehrt sie in der
 * festen Reihenfolge des Nachholens. Ein neuer Aufruf braucht nur einen neuen Handler dort, keine Schemaaenderung.
 */
interface OpenCallHandler
{
    /** Stabiler Name, wie er in `account_open_calls.operation` und in der Lesesicht steht, z. B. `disable_login`. */
    public function operation(): string;

    /** Hat sich das Konto so geaendert, dass der Aufruf nicht mehr noetig ist? Er wird dann ohne Aufruf aus der Menge genommen. */
    public function isObsolete(Account $account): bool;

    /**
     * Ruft den Anmeldedienst auf. Idempotent, weil er wiederholt wird.
     *
     * @throws \TeacherOS\Shared\AuthService\AuthServiceUnavailableException Dienst nicht erreichbar oder unlesbar: der Aufruf bleibt offen, ohne Code
     * @throws \TeacherOS\Shared\Exceptions\CodedException fachliche Ablehnung: der Aufruf bleibt offen, der Code wird festgehalten
     */
    public function execute(Account $account, AuthServiceClient $authService): void;
}
