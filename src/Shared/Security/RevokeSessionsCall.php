<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\AuthService\AuthServiceClient;

/**
 * Der Widerruf aller Sitzungen im Anmeldedienst (`revoke-sessions`, G3; Ticket #48), samt vertrauenswuerdiger Geraete.
 * Offen ab dem lokalen Setzen der Sitzungsgrenze, bis der Dienst ihn bestaetigt; solange sperrt das Backend jedes Token des
 * Kontos ({@see TokenBlockingCall}).
 */
final class RevokeSessionsCall implements TokenBlockingCall
{
    public const OPERATION = 'revoke_sessions';

    public function operation(): string
    {
        return self::OPERATION;
    }

    /** Der Widerruf bleibt gueltig, solange er offen ist: kein Zustand des Kontos macht ihn hinfaellig. */
    public function isObsolete(Account $account): bool
    {
        return false;
    }

    public function execute(Account $account, AuthServiceClient $authService): void
    {
        $authService->revokeSessions($account->publicUserId, includeTrustedDevices: true);
    }
}
