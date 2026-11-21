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
    public const ROLE_CREATED = 'role.created';
    public const ROLE_ASSIGNED = 'role.assigned';
    public const ROLE_REVOKED = 'role.revoked';
    /** Rechte, Name, Beschreibung oder MFA-Merkmal einer Rolle geaendert (Ticket #33). */
    public const ROLE_UPDATED = 'role.updated';
    public const ROLE_DELETED = 'role.deleted';

    /** Ein aktives Konto wurde von der Verwaltung deaktiviert (Ticket #41); Kontext: reason. */
    public const ACCOUNT_DEACTIVATED = 'account.deactivated';

    /** Ein deaktiviertes Konto wurde von der Verwaltung reaktiviert (Ticket #43). */
    public const ACCOUNT_REACTIVATED = 'account.reactivated';

    /** Die Verwaltung (oder das Konto selbst, Ticket #50) hat alle Sitzungen eines Kontos beendet (Ticket #48); Kontext: initiated_by (`admin` oder `self`). */
    public const ACCOUNT_SESSIONS_REVOKED = 'account.sessions_revoked';

    /** Die Verwaltung hat das TOTP eines Kontos zurueckgesetzt (Ticket #49); ohne Kontext (ob TOTP eingerichtet war, ist bei gescheitertem Abgleich unbekannt). */
    public const ACCOUNT_TOTP_RESET = 'account.totp_reset';

    public const PERMISSION_GRANTED = 'permission.granted';
    public const PERMISSION_WITHDRAWN = 'permission.withdrawn';
    public const PERMISSION_OVERRIDE_CLEARED = 'permission.override_cleared';

    /** Ein Modul wurde an der Schulinstanz freigegeben (Ticket #13); Objekt: Modul-ID, Kontext: valid_from (UTC). */
    public const MODULE_ENABLED = 'module.enabled';
    /** Die Freigabe eines Moduls wurde zurueckgenommen (Ticket #13); Objekt: Modul-ID, Kontext: valid_from (UTC). */
    public const MODULE_DISABLED = 'module.disabled';

    /** Ein Schuljahr wurde angelegt (Ticket #54); Objekt: Kennung des Schuljahres, Kontext: name (Bezeichnung). */
    public const SCHOOL_YEAR_CREATED = 'school_year.created';

    /** Ein Schuljahr wurde bearbeitet (Ticket #56); Objekt: Kennung, Kontext: old_name (alte Bezeichnung), name (neue Bezeichnung). */
    public const SCHOOL_YEAR_UPDATED = 'school_year.updated';

    /** Ein Schuljahr wurde geloescht (Ticket #56); Objekt: Kennung, Kontext: name (Bezeichnung). */
    public const SCHOOL_YEAR_DELETED = 'school_year.deleted';

    /** Eine Klasse wurde angelegt (Ticket #55); Objekt: Kennung der Klasse, Kontext: name (Bezeichnung). */
    public const CLASS_CREATED = 'class.created';

    /** Klassenstufe oder Bezeichnung einer Klasse geaendert (Ticket #57); Objekt: Kennung, Kontext: old_name (alte Bezeichnung), name (neue Bezeichnung); Klassenstufe und Schuljahr gehoeren nicht in den Kontext. */
    public const CLASS_UPDATED = 'class.updated';

    /** Eine Klasse wurde archiviert (Ticket #57); Objekt: Kennung, Kontext: name. */
    public const CLASS_ARCHIVED = 'class.archived';

    /** Eine archivierte Klasse wurde wiederhergestellt (Ticket #57); Objekt: Kennung, Kontext: name. */
    public const CLASS_RESTORED = 'class.restored';

    /** Eine unverknuepfte Klasse wurde endgueltig geloescht (Ticket #57); Objekt: Kennung, Kontext: name. */
    public const CLASS_DELETED = 'class.deleted';

    /** Ein Fach wurde angelegt (Ticket #62); Objekt: Kennung des Faches, Kontext: code (Kuerzel), name (Bezeichnung). */
    public const SUBJECT_CREATED = 'subject.created';
    /** Eine Schuleinstellung wurde geaendert (Ticket #66); Objekt: Schluessel der Einstellung, Kontext: old_value (alter Wert; ohne gespeicherten Wert der Standard), value (neuer Wert). */
    public const SCHOOL_SETTING_UPDATED = 'school_setting.updated';

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
