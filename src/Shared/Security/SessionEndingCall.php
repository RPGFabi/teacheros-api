<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\AuthService\AuthServiceClient;

/**
 * Ein Aufruf an den Anmeldedienst, der alle Sitzungen eines Kontos beendet (ADR 0002 B2 und Nachtrag #16): der Sitzungswiderruf
 * (`revoke-sessions`, G3, samt vertrauenswuerdiger Geraete; Ticket #48) und die TOTP-Zuruecksetzung (`mfa-reset`, G1: Secret,
 * Recovery-Codes, vertrauenswuerdige Geraete und alle Refresh-Tokens; sie schliesst den Widerruf ein; Ticket #49). Beide sind offen ab
 * dem lokalen Setzen der Sitzungsgrenze, bis der Dienst sie bestaetigt; solange sperrt das Backend jedes Token des Kontos
 * ({@see TokenBlockingCall}). Sie unterscheiden sich nur im Namen und im Aufruf, darum teilen sie sich diese Klasse.
 */
final class SessionEndingCall implements TokenBlockingCall
{
    public const REVOKE_SESSIONS = 'revoke_sessions';
    public const RESET_TOTP = 'reset_totp';

    private function __construct(private readonly string $operation)
    {
    }

    public static function revokeSessions(): self
    {
        return new self(self::REVOKE_SESSIONS);
    }

    public static function resetTotp(): self
    {
        return new self(self::RESET_TOTP);
    }

    public function operation(): string
    {
        return $this->operation;
    }

    /** Der Aufruf bleibt gueltig, solange er offen ist: kein Zustand des Kontos macht ihn hinfaellig (auch nicht die Deaktivierung). */
    public function isObsolete(Account $account): bool
    {
        return false;
    }

    public function execute(Account $account, AuthServiceClient $authService): void
    {
        match ($this->operation) {
            self::REVOKE_SESSIONS => $authService->revokeSessions($account->publicUserId, includeTrustedDevices: true),
            self::RESET_TOTP => $authService->resetMfa($account->publicUserId),
        };
    }
}
