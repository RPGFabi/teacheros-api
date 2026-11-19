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
     * TOTP-Zuruecksetzung (ADR 0002 Nachtrag #16). Widerruf (#48) und Zuruecksetzung (#49)
     * sind {@see SessionEndingCall}s und {@see TokenBlockingCall}s.
     *
     * @return list<OpenCallHandler>
     */
    public static function standardHandlers(): array
    {
        return [
            new DisableLoginCall(),
            SessionEndingCall::revokeSessions(),
            SessionEndingCall::resetTotp(),
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
     * Die Selbstheilung beim ersten Request mit einem Token nach der Sitzungsgrenze ({@see AuthenticationMiddleware}, ADR 0002
     * Nachtrag #16, Tickets #48, #49): holt nur die {@see TokenBlockingCall}s des Kontos nach (Widerruf, TOTP-Zuruecksetzung; die
     * Sperre braucht keinen Nachholversuch, ein gesperrtes Konto wird ohnehin abgelehnt) und liest das Konto danach frisch.
     * Gelingen alle, sperrt {@see self::blocksTokens()} nicht mehr, und die Sitzungsgrenze rueckt in derselben Transaktion auf den
     * Nachholzeitpunkt: auch das Token, das den Versuch ausgeloest hat, ist dann widerrufen und die Person meldet sich neu an.
     * Erst diese Anmeldung entsteht nach der Zuruecksetzung (ohne altes TOTP); ein Token aus der Zeit des offenen Aufrufs gilt nicht.
     * Wer es nutzt, darf keine Transaktion offen haben.
     *
     * @return Account|null das Konto nach dem Versuch; null, wenn es nicht mehr existiert
     */
    public function releaseTokens(string $accountId): ?Account
    {
        try {
            $this->run($accountId, tokenBlockingOnly: true);
        } catch (CodedException) {
            // Die Aufrufe bleiben offen und sperren weiter; das Konto wird abgelehnt.
        }
        return $this->accounts->findByPublicUserId($accountId);
    }

    /**
     * Der erste Abgleich, direkt nach dem lokalen Commit der Wirkung. Jeder Fehler (Netz, Zeitueberschreitung, 5xx,
     * unlesbare oder fachlich ablehnende Antwort) laesst die Wirkung und die offenen Aufrufe bestehen und wirft nicht.
     *
     * @return CodedException|null der Fehler des ersten gescheiterten Aufrufs ({@see AuthServiceUnavailableException}: nicht erreichbar,
     *                             sonst die fachliche Ablehnung mit Status und Code); null, wenn nichts scheiterte. Die Aufrufe bleiben
     *                             offen; ein Verwalter wiederholt den Abgleich (oder Ticket #39 automatisch).
     */
    public function attempt(string $accountId): ?CodedException
    {
        try {
            $this->run($accountId);
            return null;
        } catch (CodedException $e) {
            return $e;
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

    /** @param bool $tokenBlockingOnly nur die {@see TokenBlockingCall}s nachholen und danach die Sitzungsgrenze setzen ({@see self::releaseTokens()}) */
    private function run(string $accountId, bool $tokenBlockingOnly = false): void
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
            $open = array_values(array_filter(
                $this->ordered($account->openCalls),
                static fn (OpenCall $call) => isset($handlers[$call->operation]) && (!$tokenBlockingOnly || $handlers[$call->operation] instanceof TokenBlockingCall),
            ));
            $blocking = count(array_filter($open, static fn (OpenCall $call) => !$handlers[$call->operation]->isObsolete($account)));
            $released = 0;

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
                    $released++;
                } catch (AuthServiceUnavailableException $e) {
                    $failure ??= $e;
                    break;
                } catch (CodedException $e) {
                    $this->accounts->rejectOpenCall($accountId, $call->operation, $e->errorCode);
                    $failure ??= $e;
                }
            }
            if ($tokenBlockingOnly && $blocking > 0 && $released === $blocking) {
                $this->accounts->revokeSessions($accountId);
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
