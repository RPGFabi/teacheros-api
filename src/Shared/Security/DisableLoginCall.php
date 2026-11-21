<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\AuthService\AuthServiceClient;

/** Die Sperre des Logins im Anmeldedienst (`disable-login`, G4a; Tickets #42, #47). Offen ab der Deaktivierung, bis der Dienst sie bestaetigt. */
final class DisableLoginCall implements OpenCallHandler
{
    public const OPERATION = 'disable_login';

    public function operation(): string
    {
        return self::OPERATION;
    }

    /** Ein Konto, das nicht (mehr) deaktiviert ist, braucht keine Sperre. */
    public function isObsolete(Account $account): bool
    {
        return $account->status !== AccountStatus::Inactive || $account->inactiveReason === null;
    }

    public function execute(Account $account, AuthServiceClient $authService): void
    {
        $authService->disableLogin($account->publicUserId, ($account->inactiveReason ?? throw new \LogicException('Ein Konto ohne Sperrgrund hat keine offene Sperre.'))->value);
    }
}
