<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\AuthService\AuthServiceClient;
use TeacherOS\Shared\AuthService\AuthServiceUnavailableException;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Spiegelt die MFA-Pflicht an den Anmeldedienst (ADR 0002 G2, Ticket #15). {@see MfaRequirement::recomputeFor()} markiert
 * jedes Konto, dessen `mfa_required` sich geaendert hat (`mfa_sync_pending`); nach dem Commit meldet {@see self::pushPending()}
 * alle markierten Konten mit `mfa-requirement` (je Wert, hoechstens 200 IDs je Aufruf) und leert die Markierung.
 *
 * Ein Fehler dort aendert lokal nichts: die Pflicht wird von der API durchgesetzt (B3), die Markierung bleibt, und der
 * naechste Abgleich (nach jeder weiteren Rollen- oder Merkmalsaenderung) meldet das Konto erneut. Der Aufruf ist idempotent.
 * Ein automatisches Wiederholen ohne Anlass ist Ticket #39 (Outbox).
 */
final class MfaRequirementSync
{
    private const BATCH = 200;

    public function __construct(private readonly AuthServiceClient $authService = new AuthServiceClient())
    {
    }

    /** Meldet alle offenen Pflichtaenderungen; wirft nie (Ausfall oder Ablehnung lassen die Markierung stehen). */
    public function pushPending(): void
    {
        $pdo = DB::getInstance();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Die MFA-Pflicht wird erst nach dem Commit gespiegelt.');
        }

        $byValue = [0 => [], 1 => []];
        foreach ($pdo->query('SELECT `public_user_id`, `mfa_required` FROM `accounts` WHERE `mfa_sync_pending` = 1 ORDER BY `id`') as $row) {
            $byValue[(int) $row['mfa_required']][] = (string) $row['public_user_id'];
        }

        $clear = static fn (array $ids, int $required) => $pdo->prepare(
            'UPDATE `accounts` SET `mfa_sync_pending` = 0 WHERE `mfa_required` = ? AND `public_user_id` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        )->execute([$required, ...$ids]);

        foreach ($byValue as $required => $ids) {
            foreach (array_chunk($ids, self::BATCH) as $chunk) {
                try {
                    $this->authService->setMfaRequirement($chunk, $required === 1);
                } catch (AuthServiceUnavailableException) {
                    return; // Dienst nicht erreichbar: weitere Aufrufe warten nur auf dieselbe Zeitueberschreitung.
                } catch (CodedException) {
                    continue; // fachliche Ablehnung: Markierung bleibt, die uebrigen Gruppen werden trotzdem gemeldet.
                }
                // Nur leeren, wo der gemeldete Wert noch gilt; eine gleichzeitige Aenderung hat die Markierung neu gesetzt.
                $clear($chunk, $required);
            }
        }
    }
}
