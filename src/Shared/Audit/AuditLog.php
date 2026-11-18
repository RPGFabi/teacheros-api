<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Audit;

use Hoelzle\Core\Database\DB;

/**
 * Schreibt datensparsame Audit-Eintraege: wer, wann, welche Handlung, welches Objekt.
 * Keine E-Mail-Adressen oder anderen fachlichen Daten; `$context` nur fuer technische Angaben.
 * Schreibt ueber dieselbe Verbindung wie die fachliche Aenderung und damit in deren Transaktion.
 */
final class AuditLog
{
    public const ACCOUNT_INVITED = 'account.invited';
    public const ACCOUNT_REINVITED = 'account.reinvited';
    /** Eine nie angenommene Einladung wurde ersetzt; das Konto (Objekt) ist gelöscht. */
    public const ACCOUNT_INVITATION_WITHDRAWN = 'account.invitation_withdrawn';
    public const ACCOUNT_ACTIVATED = 'account.activated';
    public const ROLE_ASSIGNED = 'role.assigned';
    public const ROLE_REVOKED = 'role.revoked';

    /** @param array<string,scalar>|null $context */
    public function record(Actor $actor, string $action, string $objectType, string $objectId, ?array $context = null): void
    {
        DB::getInstance()->prepare(
            'INSERT INTO `audit_log` (`actor_type`, `actor_account_id`, `action`, `object_type`, `object_id`, `context`)
             VALUES (:type, :actor, :action, :otype, :oid, :context)'
        )->execute([
            'type' => $actor->type,
            'actor' => $actor->accountId,
            'action' => $action,
            'otype' => $objectType,
            'oid' => $objectId,
            'context' => $context === null ? null : json_encode($context, JSON_THROW_ON_ERROR),
        ]);
    }
}
