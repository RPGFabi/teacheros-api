<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\AuthService\AuthServiceClient;
use TeacherOS\Shared\AuthService\AuthServiceUnavailableException;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Der Abgleich einer lokalen Deaktivierung mit dem Anmeldedienst (`disable-login`, G4a; ADR 0002 B2 und Nachtrag #12,
 * Tickets #42, Review #12). Die Deaktivierung ist dann schon lokal festgeschrieben und hat die Markierung "Abgleich offen"
 * (`accounts.login_sync_pending`) gesetzt; erst der erfolgreiche Aufruf leert sie. Die Deaktivierung wird nie
 * rueckgaengig gemacht. Ein Wiederholen schreibt keinen Audit-Eintrag; ein automatisches Wiederholen (Outbox) ist Ticket #39.
 *
 * Lehnt der Dienst fachlich ab (4xx mit Code), bleibt die Markierung bestehen und der Code steht in `login_sync_error`;
 * Nichterreichbarkeit setzt keinen Code.
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
     * Der erste Abgleich, direkt nach dem lokalen Commit der Deaktivierung. Jeder Fehler (Netz, Zeitueberschreitung,
     * 5xx, unlesbare oder fachlich ablehnende Antwort) laesst Deaktivierung und Markierung bestehen und wirft nicht.
     */
    public function syncAfterDeactivation(string $accountId): void
    {
        try {
            $this->run($accountId);
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
        $this->run($accountId);
    }

    private function run(string $accountId): void
    {
        $pdo = DB::getInstance();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Der Abgleich ruft den Anmeldedienst erst auf, wenn die Deaktivierung festgeschrieben ist.');
        }
        $pdo->beginTransaction();
        $calling = false;
        try {
            $account = $this->accounts->lock($accountId);
            $reason = $account->awaitsLoginSync() ? $account->inactiveReason : null;
            if ($reason === null) {
                throw new CodedException('sync_not_pending', 409, 'Es ist kein Abgleich mit dem Anmeldedienst offen.');
            }

            $calling = true;
            $this->authService->disableLogin($accountId, $reason->value);
            $calling = false;
            $this->accounts->markLoginSynced($accountId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($calling && $e instanceof CodedException && !$e instanceof AuthServiceUnavailableException) {
                // Fachliche Ablehnung des Dienstes: "Abgleich offen" bleibt, der Code wird festgehalten.
                $this->accounts->markLoginSyncRejected($accountId, $e->errorCode);
            }
            throw $e;
        }
    }
}
