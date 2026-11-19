<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\AuthService\AuthServiceClient;

/**
 * Die TOTP-Zuruecksetzung im Anmeldedienst (`mfa-reset`, G1; Ticket #49): Secret, Recovery-Codes, vertrauenswuerdige Geraete und
 * alle Refresh-Tokens sind danach weg (sie schliesst den Sitzungswiderruf ein). Offen ab dem lokalen Setzen der Sitzungsgrenze,
 * bis der Dienst sie bestaetigt; solange sperrt das Backend jedes Token des Kontos ({@see TokenBlockingCall}).
 */
final class ResetMfaCall implements TokenBlockingCall
{
    public const OPERATION = 'reset_mfa';

    public function operation(): string
    {
        return self::OPERATION;
    }

    /** Die Zuruecksetzung bleibt gueltig, solange sie offen ist: kein Zustand des Kontos macht sie hinfaellig (auch nicht die Deaktivierung). */
    public function isObsolete(Account $account): bool
    {
        return false;
    }

    public function execute(Account $account, AuthServiceClient $authService): void
    {
        $authService->resetMfa($account->publicUserId);
    }
}
