<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\AuthService\AuthServiceClient;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * "Alle Sitzungen eines Kontos beenden" (Sitzungswiderruf, ADR 0002 B2 und Nachtrag #16, Tickets #48, #50). Der gemeinsame
 * Baustein von Verwaltung (#48) und "Mein Konto" (#50); die Regeln des jeweiligen Weges prueft der Aufrufer vorab
 * ({@see AccountRevocationRules}) oder in der Transaktion ueber `$precondition` von {@see self::revoke()}.
 *
 * Reihenfolge nach B2: zuerst lokal die Sitzungsgrenze `sessions_valid_after`, der offene Aufruf `revoke_sessions` und der
 * Audit-Eintrag `account.sessions_revoked` in einer Transaktion mit gesperrter Kontozeile, nach dem Commit G3 (`revoke-sessions`,
 * samt vertrauenswuerdiger Geraete). Scheitert G3, bleibt der Aufruf offen und das Backend sperrt jedes Token des Kontos
 * ({@see TokenBlockingCall}), bis er nachgeholt ist.
 */
final class SessionRevocation
{
    public function __construct(
        private readonly AuditLog $audit = new AuditLog(),
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly AuthServiceClient $authService = new AuthServiceClient(),
    ) {
    }

    /**
     * Der Aufrufer darf keine eigene Transaktion offen haben (der Aufruf an den Anmeldedienst folgt erst nach dem Commit).
     *
     * @param int $attempts so oft wird G3 hoechstens versucht, bis es gelingt (mindestens 1); "Mein Konto" nimmt drei (Ticket #50)
     * @param (callable(Account): ?CodedException)|null $precondition laeuft in der Transaktion nach dem Sperren der Kontozeile; eine Ablehnung bricht ab, ohne etwas zu aendern
     * @return bool true, wenn der Anmeldedienst den Widerruf bestaetigt hat; false, wenn der Aufruf offen bleibt (Nichterreichbarkeit oder fachliche Ablehnung)
     * @throws CodedException `account_not_found` (404) oder die Ablehnung von $precondition
     */
    public function revoke(Actor $actor, string $accountId, RevocationInitiator $initiator, int $attempts = 1, ?callable $precondition = null): bool
    {
        return (new SessionTermination($this->audit, $this->accounts, $this->authService))->terminate(
            $actor,
            $accountId,
            RevokeSessionsCall::OPERATION,
            AuditLog::ACCOUNT_SESSIONS_REVOKED,
            ['initiated_by' => $initiator->value],
            $attempts,
            $precondition,
        );
    }
}
