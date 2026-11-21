<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\AuthService\AuthServiceClient;
use TeacherOS\Shared\AuthService\AuthServiceUnavailableException;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Der gemeinsame Ablauf aller Aktionen, die alle Sitzungen eines Kontos beenden und dafuer einen Aufruf an den Anmeldedienst
 * brauchen (ADR 0002 B2 und Nachtrag #16): der Sitzungswiderruf ({@see SessionRevocation}, G3) und die TOTP-Zuruecksetzung
 * (G1, Ticket #49; sie schliesst den Widerruf ein). Reihenfolge nach B2: zuerst lokal die Sitzungsgrenze `sessions_valid_after`,
 * der offene Aufruf und der Audit-Eintrag in einer Transaktion mit gesperrter Kontozeile, nach dem Commit der Aufruf an den
 * Anmeldedienst. Scheitert er, bleibt der Aufruf offen und das Backend sperrt jedes Token des Kontos ({@see TokenBlockingCall}),
 * bis er nachgeholt ist ({@see OpenCalls}). Die Regeln des jeweiligen Weges prueft der Aufrufer ueber `$precondition`.
 */
final class SessionTermination
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
     * @param string $operation der offene Aufruf ({@see OpenCallHandler::operation()}), ein {@see TokenBlockingCall} aus {@see OpenCalls::standardHandlers()}
     * @param string $auditAction Aktion des Audit-Eintrags ({@see AuditLog})
     * @param array<string,scalar> $auditContext Kontext des Audit-Eintrags
     * @param int $attempts so oft wird der Aufruf hoechstens versucht, bis er gelingt (mindestens 1); eine fachliche Ablehnung des
     *                      Anmeldedienstes wird nicht wiederholt, nur die Nichterreichbarkeit
     * @param (callable(Account): ?CodedException)|null $precondition laeuft in der Transaktion nach dem Sperren der Kontozeile; eine Ablehnung bricht ab, ohne etwas zu aendern
     * @return CodedException|null null, wenn der Anmeldedienst den Aufruf bestaetigt hat; sonst der Grund, warum er offen bleibt:
     *                             {@see AuthServiceUnavailableException} (nicht erreichbar) oder die fachliche Ablehnung mit Status und Code
     * @throws CodedException `account_not_found` (404) oder die Ablehnung von $precondition
     */
    public function terminate(Actor $actor, string $accountId, string $operation, string $auditAction, array $auditContext, int $attempts = 1, ?callable $precondition = null): ?CodedException
    {
        $pdo = DB::getInstance();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Der Aufruf an den Anmeldedienst folgt erst, wenn die lokale Wirkung festgeschrieben ist.');
        }
        $pdo->beginTransaction();
        try {
            $account = $this->accounts->lock($accountId);
            if ($precondition !== null && ($refusal = $precondition($account)) !== null) {
                throw $refusal;
            }
            $this->accounts->revokeSessions($accountId);
            $this->accounts->addOpenCall($accountId, $operation);
            $this->audit->record($actor, $auditAction, 'account', $accountId, $auditContext);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $openCalls = new OpenCalls($this->authService, $this->accounts);
        $failure = null;
        for ($attempt = 0; $attempt < max(1, $attempts); $attempt++) {
            $failure = $openCalls->attempt($accountId);
            if ($this->accounts->findByPublicUserId($accountId)?->openCall($operation) === null) {
                return null;
            }
            if (!$failure instanceof AuthServiceUnavailableException) {
                break; // eine fachliche Ablehnung bleibt beim Wiederholen, was sie ist
            }
        }
        return $failure ?? new AuthServiceUnavailableException('der Aufruf ist offen geblieben');
    }
}
