<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\AuthService\AuthServiceClient;
use TeacherOS\Shared\AuthService\AuthServiceUnavailableException;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Der Abgleich lokaler Wirkungen mit dem Anmeldedienst als Menge offener Aufrufe je Konto (ADR 0002 B2 und Nachtrag
 * #12/#16, Tickets #42, #47). Eine lokale Wirkung (heute die Sperre, `disable-login`) ist schon festgeschrieben und
 * hat ihren Aufruf als offen markiert ({@see AccountRepository::addOpenCall()}, in derselben Transaktion); erst der
 * erfolgreiche Aufruf nimmt ihn aus der Menge. Die lokale Wirkung wird nie rueckgaengig gemacht. Ein Wiederholen schreibt
 * keinen Audit-Eintrag; ein automatisches Wiederholen (Outbox) ist Ticket #39.
 *
 * Jeder Aufruf traegt seinen eigenen Ablehnungscode: lehnt der Dienst fachlich ab (4xx mit Code), bleibt der Aufruf offen
 * und der Code steht dabei; Nichterreichbarkeit setzt keinen Code. {@see self::resync()} holt alle offenen Aufrufe in
 * der festen Reihenfolge der Handler nach: Erfolg nimmt den Aufruf aus der Menge, eine Ablehnung hindert die folgenden
 * nicht, Nichterreichbarkeit beendet den Lauf (die folgenden warteten nur auf dieselbe Zeitueberschreitung).
 *
 * Aufrufe und Markierungen stehen in einer Transaktion mit gesperrter Kontozeile: eine gleichzeitige Reaktivierung
 * (Ticket #43, sperrt dieselbe Zeile) sieht entweder einen offenen Aufruf und wartet, oder das Konto ist schon abgeglichen.
 */
final class OpenCalls
{
    /** @var list<OpenCallHandler> */
    private readonly array $handlers;

    /**
     * @param list<OpenCallHandler>|null $handlers Handler in der festen Reihenfolge des Nachholens; null = {@see self::standardHandlers()}
     */
    public function __construct(
        private readonly AuthServiceClient $authService = new AuthServiceClient(),
        private readonly AccountRepository $accounts = new AccountRepository(),
        ?array $handlers = null,
    ) {
        $this->handlers = $handlers ?? self::standardHandlers();
    }

    /**
     * Alle Aufrufe, die ein Konto offen tragen kann, in der festen Reihenfolge des Nachholens: Sperre, Sitzungswiderruf,
     * TOTP-Zuruecksetzung (ADR 0002 Nachtrag #16). Der Widerruf (#48) steht schon hier; die Zuruecksetzung (#49) traegt
     * ihren Handler an ihrer Stelle ein (und markiert ihn als {@see TokenBlockingCall}); sonst aendert sich nichts.
     *
     * @return list<OpenCallHandler>
     */
    public static function standardHandlers(): array
    {
        return [
            new DisableLoginCall(),
            new RevokeSessionsCall(),
        ];
    }

    /**
     * Die offenen Aufrufe in der festen Reihenfolge (Aufrufe ohne Handler zuletzt).
     *
     * @param list<OpenCall> $calls
     * @return list<OpenCall>
     */
    public function ordered(array $calls): array
    {
        $position = array_flip(array_map(static fn (OpenCallHandler $h) => $h->operation(), $this->handlers));
        usort($calls, static fn (OpenCall $a, OpenCall $b) => ($position[$a->operation] ?? PHP_INT_MAX) <=> ($position[$b->operation] ?? PHP_INT_MAX));
        return $calls;
    }

    /**
     * Sperrt ein offener Aufruf dieses Kontos jedes Token ({@see TokenBlockingCall})? Offene Aufrufe ohne Handler oder
     * hinfaellige Aufrufe sperren nicht.
     */
    public function blocksTokens(Account $account): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler instanceof TokenBlockingCall && $account->openCall($handler->operation()) !== null && !$handler->isObsolete($account)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Der Versuch beim ersten Request mit einem neuen Token ({@see AuthenticationMiddleware}, ADR 0002 Nachtrag #16, Ticket #48):
     * holt die offenen Aufrufe des Kontos nach (wie {@see self::attempt()}) und liest das Konto danach frisch. Gelingt es, sperrt
     * {@see self::blocksTokens()} nicht mehr und das Konto ist sofort nutzbar. Wer es nutzt, darf keine Transaktion offen haben.
     *
     * @return Account|null das Konto nach dem Versuch; null, wenn es nicht mehr existiert
     */
    public function releaseTokens(string $accountId): ?Account
    {
        $this->attempt($accountId);
        return $this->accounts->findByPublicUserId($accountId);
    }

    /**
     * Der erste Abgleich, direkt nach dem lokalen Commit der Wirkung. Jeder Fehler (Netz, Zeitueberschreitung, 5xx,
     * unlesbare oder fachlich ablehnende Antwort) laesst die Wirkung und die offenen Aufrufe bestehen und wirft nicht.
     */
    public function attempt(string $accountId): void
    {
        try {
            $this->run($accountId);
        } catch (CodedException) {
            // Die Aufrufe bleiben offen; ein Verwalter wiederholt den Abgleich (oder Ticket #39 automatisch).
        }
    }

    /**
     * "Abgleich wiederholen": holt alle offenen Aufrufe in fester Reihenfolge nach. Was gelingt, verlaesst die Menge; was
     * abgelehnt wird, behaelt seinen Code. Geworfen wird der Fehler des ersten gescheiterten Aufrufs.
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
            throw new \LogicException('Der Abgleich ruft den Anmeldedienst erst auf, wenn die lokale Wirkung festgeschrieben ist.');
        }
        $failure = null;
        $pdo->beginTransaction();
        try {
            $account = $this->accounts->lock($accountId);
            $handlers = [];
            foreach ($this->handlers as $handler) {
                $handlers[$handler->operation()] = $handler;
            }
            $open = array_values(array_filter($this->ordered($account->openCalls), static fn (OpenCall $call) => isset($handlers[$call->operation])));

            $attempted = false;
            foreach ($open as $call) {
                $handler = $handlers[$call->operation];
                if ($handler->isObsolete($account)) {
                    $this->accounts->removeOpenCall($accountId, $call->operation);
                    continue;
                }
                $attempted = true;
                try {
                    $handler->execute($account, $this->authService);
                    $this->accounts->removeOpenCall($accountId, $call->operation);
                } catch (AuthServiceUnavailableException $e) {
                    $failure ??= $e;
                    break;
                } catch (CodedException $e) {
                    $this->accounts->rejectOpenCall($accountId, $call->operation, $e->errorCode);
                    $failure ??= $e;
                }
            }
            if (!$attempted && $failure === null) {
                $failure = new CodedException('sync_not_pending', 409, 'Es ist kein Abgleich mit dem Anmeldedienst offen.');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
