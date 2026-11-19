<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Accounts;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\AuthService\AuthServiceClient;
use TeacherOS\Shared\AuthService\AuthServiceUnavailableException;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AccountRepository;

/**
 * Spiegelt die lokale Deaktivierung eines Kontos an den Anmeldedienst (`disable-login`, G4a; ADR 0002 B2 und
 * Nachtrag #12, Ticket #42). Die Deaktivierung selbst ist dann schon lokal festgeschrieben und setzt die Markierung
 * "Abgleich offen" (`accounts.login_sync_pending`); erst der erfolgreiche Aufruf leert sie. Die Sperre wird nie
 * rueckgaengig gemacht. Das Nachholen schreibt keinen Audit-Eintrag; ein automatisches Nachholen (Outbox) ist Ticket #39.
 *
 * Aufruf und Markierung stehen in einer Transaktion mit gesperrter Kontozeile: eine gleichzeitige Reaktivierung
 * (Ticket #43, sperrt dieselbe Zeile) sieht entweder "Abgleich offen" und wartet, oder das Konto ist schon abgeglichen.
 */
final class AccountLoginSync
{
    public function __construct(
        private readonly AuthServiceClient $authService = new AuthServiceClient(),
        private readonly AccountRepository $accounts = new AccountRepository(),
    ) {
    }

    /**
     * Direkt nach der lokalen Deaktivierung: meldet sie dem Anmeldedienst. Jeder Fehler (Netz, Zeitueberschreitung,
     * 5xx, unlesbare oder fachlich ablehnende Antwort) laesst Sperre und Markierung unveraendert und wirft nicht.
     */
    public function mirrorDeactivation(string $accountId): void
    {
        try {
            $this->push($accountId);
        } catch (CodedException) {
            // Markierung "Abgleich offen" bleibt; ein Verwalter wiederholt den Abgleich (oder Ticket #39 automatisch).
        }
    }

    /**
     * "Abgleich wiederholen": ruft `disable-login` erneut auf und leert bei Erfolg die Markierung.
     *
     * @throws CodedException `account_not_found` (404), `sync_not_pending` (409: nichts abzugleichen), `auth_unavailable` (502)
     *                        oder die fachliche Ablehnung des Anmeldedienstes mit dessen Status und Code
     */
    public function resync(string $accountId): void
    {
        $this->push($accountId);
    }

    private function push(string $accountId): void
    {
        $pdo = DB::getInstance();
        $pdo->beginTransaction();
        $calling = false;
        try {
            $lock = $pdo->prepare('SELECT `id` FROM `accounts` WHERE `public_user_id` = :id FOR UPDATE');
            $lock->execute(['id' => $accountId]);
            if ($lock->fetchColumn() === false) {
                throw new CodedException('account_not_found', 404, 'Das Konto gibt es nicht.');
            }
            $account = $this->accounts->findByPublicUserId($accountId);
            if ($account === null || $account->isActive() || !$account->loginSyncPending || $account->inactiveReason === null) {
                throw new CodedException('sync_not_pending', 409, 'Es ist kein Abgleich mit dem Anmeldedienst offen.');
            }

            $calling = true;
            $this->authService->disableLogin($accountId, $account->inactiveReason);
            $calling = false;
            $this->accounts->markLoginSynced($accountId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($calling && $e instanceof CodedException && !$e instanceof AuthServiceUnavailableException) {
                // Fachliche Ablehnung: "Abgleich offen" bleibt, der Code wird festgehalten (Review #12). Nichterreichbarkeit setzt keinen Code.
                $this->accounts->markLoginSyncRejected($accountId, $e->errorCode);
            }
            throw $e;
        }
    }
}
