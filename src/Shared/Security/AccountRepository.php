<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\Exceptions\CodedException;

/** Liest Konten bei jedem Aufruf frisch aus der Datenbank (kein Cache, ADR 0002 B1). */
final class AccountRepository
{
    private const COLUMNS = '`public_user_id`, `status`, `mfa_required`, `email`, `login_name`, `inactive_reason`,
        UNIX_TIMESTAMP(`sessions_valid_after`) AS sessions_valid_after_ts, UNIX_TIMESTAMP(`inactive_since`) AS inactive_since_ts';

    public function findByPublicUserId(string $publicUserId): ?Account
    {
        return $this->findOne('`public_user_id` = :v', $publicUserId);
    }

    /**
     * Sperrt die Zeile des Kontos (`FOR UPDATE`; nur in einer Transaktion: gleichzeitige Aenderungen desselben Kontos
     * laufen nacheinander) und liest es danach frisch.
     *
     * @throws CodedException `account_not_found` (404)
     */
    public function lock(string $publicUserId): Account
    {
        $lock = DB::getInstance()->prepare('SELECT `id` FROM `accounts` WHERE `public_user_id` = :id FOR UPDATE');
        $lock->execute(['id' => $publicUserId]);
        if ($lock->fetchColumn() === false) {
            throw new CodedException('account_not_found', 404, 'Das Konto gibt es nicht.');
        }
        return $this->findByPublicUserId($publicUserId) ?? throw new CodedException('account_not_found', 404, 'Das Konto gibt es nicht.');
    }

    public function findByEmail(string $email): ?Account
    {
        return $this->findOne('`email` = :v', $email);
    }

    public function findByLoginName(string $loginName): ?Account
    {
        return $this->findOne('`login_name` = :v', $loginName);
    }

    /**
     * Alle Konten fuer die Kontenliste, aelteste zuerst. `invited_at` als ISO-8601 (UTC) oder null.
     *
     * Deaktivierte Konten tragen zusaetzlich `inactive_since` (ISO-8601, UTC), sonst null, und `login_sync_pending`
     * ("Abgleich offen" der Sperre, Ticket #42; nur bei deaktivierten Konten true).
     *
     * @return list<array{id:string,email:?string,login_name:string,status:string,invited_at:?string,inactive_since:?string,login_sync_pending:bool}>
     */
    public function listAll(): array
    {
        $rows = DB::getInstance()->prepare(
            'SELECT `public_user_id`, `email`, `login_name`, `status`, UNIX_TIMESTAMP(`invited_at`) AS invited_ts, UNIX_TIMESTAMP(`inactive_since`) AS inactive_ts,
                EXISTS (SELECT 1 FROM `account_open_calls` c WHERE c.`account_id` = `accounts`.`public_user_id` AND c.`operation` = :disable_login) AS login_sync_pending
             FROM `accounts` ORDER BY `id`'
        );
        $rows->execute(['disable_login' => DisableLoginCall::OPERATION]);
        $rows = $rows->fetchAll();
        return array_map(static fn (array $r) => [
            'id' => $r['public_user_id'],
            'email' => $r['email'],
            'login_name' => $r['login_name'],
            'status' => $r['status'],
            'invited_at' => $r['invited_ts'] === null ? null : gmdate('c', (int) $r['invited_ts']),
            'inactive_since' => $r['status'] === AccountStatus::Inactive->value && $r['inactive_ts'] !== null ? gmdate('c', (int) $r['inactive_ts']) : null,
            'login_sync_pending' => $r['status'] === AccountStatus::Inactive->value && (bool) $r['login_sync_pending'],
        ], $rows);
    }

    /** "Eingeladen am" eines Kontos als ISO-8601 (UTC) oder null. */
    public function invitedAt(string $publicUserId): ?string
    {
        $stmt = DB::getInstance()->prepare('SELECT UNIX_TIMESTAMP(`invited_at`) FROM `accounts` WHERE `public_user_id` = :id');
        $stmt->execute(['id' => $publicUserId]);
        $ts = $stmt->fetchColumn();
        return $ts === false || $ts === null ? null : gmdate('c', (int) $ts);
    }

    /** Legt ein Konto an. Ohne Anmeldenamen gilt die E-Mail, sonst die public_user_id (Entwickler-Skript). */
    public function create(string $publicUserId, AccountStatus $status = AccountStatus::Invited, ?string $email = null, ?string $loginName = null): void
    {
        DB::getInstance()->prepare(
            'INSERT INTO `accounts` (`public_user_id`, `status`, `email`, `login_name`, `invited_at`)
             VALUES (:id, :status, :email, :login_name, IF(:status2 = :invited, CURRENT_TIMESTAMP, NULL))'
        )->execute([
            'id' => $publicUserId,
            'status' => $status->value,
            'status2' => $status->value,
            'invited' => AccountStatus::Invited->value,
            'email' => $email,
            'login_name' => $loginName ?? $email ?? $publicUserId,
        ]);
    }

    /** Setzt "Eingeladen am" neu (erneute Einladung eines noch nicht angenommenen Kontos). */
    public function markInvitedNow(string $publicUserId): void
    {
        DB::getInstance()->prepare('UPDATE `accounts` SET `invited_at` = CURRENT_TIMESTAMP WHERE `public_user_id` = :id')
            ->execute(['id' => $publicUserId]);
    }

    /** Hebt ein `invited`-Konto auf `active`. @return bool true nur, wenn dieser Aufruf den Wechsel vollzogen hat */
    public function activateIfInvited(string $publicUserId): bool
    {
        $stmt = DB::getInstance()->prepare('UPDATE `accounts` SET `status` = :active WHERE `public_user_id` = :id AND `status` = :invited');
        $stmt->execute(['id' => $publicUserId, 'active' => AccountStatus::Active->value, 'invited' => AccountStatus::Invited->value]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Deaktiviert ein aktives Konto: Status, Grund, `inactive_since` und `sessions_valid_after` (ADR 0002 B2) in einem
     * Schritt. Den offenen Aufruf der Sperre ({@see self::addOpenCall()}) traegt der Aufrufer in derselben Transaktion
     * ein; er prueft auch die Regeln und sperrt.
     *
     * @return bool true nur, wenn dieser Aufruf den Wechsel vollzogen hat
     */
    public function deactivate(string $publicUserId, InactiveReason $reason): bool
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE `accounts` SET `status` = :inactive, `inactive_reason` = :reason, `inactive_since` = CURRENT_TIMESTAMP, `sessions_valid_after` = CURRENT_TIMESTAMP
             WHERE `public_user_id` = :id AND `status` = :active'
        );
        $stmt->execute(['id' => $publicUserId, 'inactive' => AccountStatus::Inactive->value, 'reason' => $reason->value, 'active' => AccountStatus::Active->value]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Setzt die Sitzungsgrenze `sessions_valid_after` auf jetzt (ADR 0002 B2, Sitzungswiderruf, Ticket #48): Tokens mit `iat`
     * bis zu dieser Sekunde sind widerrufen. Den offenen Aufruf ({@see self::addOpenCall()}) traegt der Aufrufer in derselben
     * Transaktion ein; er prueft auch die Regeln und sperrt.
     */
    public function revokeSessions(string $publicUserId): void
    {
        DB::getInstance()->prepare('UPDATE `accounts` SET `sessions_valid_after` = CURRENT_TIMESTAMP WHERE `public_user_id` = :id')
            ->execute(['id' => $publicUserId]);
    }

    /**
     * Reaktiviert ein deaktiviertes Konto: Status `active`, Grund, `inactive_since` und der offene Aufruf der Sperre entfallen.
     * `sessions_valid_after` bleibt, alte Tokens bleiben ungueltig (ADR 0002 B2). Der Aufrufer prueft Regeln, hat den
     * Anmeldedienst unterrichtet und sperrt in seiner Transaktion.
     *
     * @return bool true nur, wenn dieser Aufruf den Wechsel vollzogen hat
     */
    public function reactivate(string $publicUserId): bool
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE `accounts` SET `status` = :active, `inactive_reason` = NULL, `inactive_since` = NULL
             WHERE `public_user_id` = :id AND `status` = :inactive'
        );
        $stmt->execute(['id' => $publicUserId, 'active' => AccountStatus::Active->value, 'inactive' => AccountStatus::Inactive->value]);
        $changed = $stmt->rowCount() === 1;
        if ($changed) {
            $this->removeOpenCall($publicUserId, DisableLoginCall::OPERATION);
        }
        return $changed;
    }

    /**
     * Traegt einen offenen Aufruf ein ("Abgleich offen", Ticket #47); ein schon offener Aufruf bleibt einer, sein Code
     * wird leer. Der Aufrufer sperrt das Konto und schreibt in derselben Transaktion die lokale Wirkung.
     */
    public function addOpenCall(string $publicUserId, string $operation): void
    {
        DB::getInstance()->prepare(
            'INSERT INTO `account_open_calls` (`account_id`, `operation`) VALUES (:id, :operation) ON DUPLICATE KEY UPDATE `rejection_code` = NULL'
        )->execute(['id' => $publicUserId, 'operation' => $operation]);
    }

    /** Nimmt einen offenen Aufruf aus der Menge (der Anmeldedienst ist unterrichtet oder der Aufruf ist hinfaellig). */
    public function removeOpenCall(string $publicUserId, string $operation): void
    {
        DB::getInstance()->prepare('DELETE FROM `account_open_calls` WHERE `account_id` = :id AND `operation` = :operation')
            ->execute(['id' => $publicUserId, 'operation' => $operation]);
    }

    /** Haelt fest, mit welchem Code der Anmeldedienst einen offenen Aufruf fachlich abgelehnt hat; der Aufruf bleibt offen. */
    public function rejectOpenCall(string $publicUserId, string $operation, string $code): void
    {
        DB::getInstance()->prepare('UPDATE `account_open_calls` SET `rejection_code` = :code WHERE `account_id` = :id AND `operation` = :operation')
            ->execute(['id' => $publicUserId, 'operation' => $operation, 'code' => $code]);
    }

    /** @return list<OpenCall> die offenen Aufrufe des Kontos, ungeordnet ({@see OpenCalls::ordered()}) */
    private function openCallsOf(string $publicUserId): array
    {
        $stmt = DB::getInstance()->prepare('SELECT `operation`, `rejection_code` FROM `account_open_calls` WHERE `account_id` = :id ORDER BY `id`');
        $stmt->execute(['id' => $publicUserId]);
        return array_map(static fn (array $r) => new OpenCall($r['operation'], $r['rejection_code']), $stmt->fetchAll());
    }

    public function delete(string $publicUserId): void
    {
        DB::getInstance()->prepare('DELETE FROM `accounts` WHERE `public_user_id` = :id')->execute(['id' => $publicUserId]);
    }

    /** Gibt es ein aktives Konto mit der Rolle $roleKey? */
    public function existsActiveWithRole(string $roleKey): bool
    {
        return $this->withRole($roleKey, AccountStatus::Active) !== [];
    }

    /** @return list<string> public_user_ids der nur eingeladenen Konten mit der Rolle $roleKey */
    public function invitedWithRole(string $roleKey): array
    {
        return $this->withRole($roleKey, AccountStatus::Invited);
    }

    /** @return list<string> */
    private function withRole(string $roleKey, AccountStatus $status): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT a.`public_user_id` FROM `accounts` a
             JOIN `permission_user_roles` ur ON ur.`user_uuid` = a.`public_user_id`
             JOIN `permission_roles` r ON r.`id` = ur.`role_id`
             WHERE r.`role_key` = :role AND a.`status` = :status ORDER BY a.`id`'
        );
        $stmt->execute(['role' => $roleKey, 'status' => $status->value]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function findOne(string $where, string $value): ?Account
    {
        $stmt = DB::getInstance()->prepare('SELECT ' . self::COLUMNS . ' FROM `accounts` WHERE ' . $where);
        $stmt->execute(['v' => $value]);
        $row = $stmt->fetch();
        return $row === false
            ? null
            : new Account(
                $row['public_user_id'],
                AccountStatus::from($row['status']),
                (bool) $row['mfa_required'],
                $row['email'],
                $row['login_name'],
                $row['sessions_valid_after_ts'] === null ? null : (int) $row['sessions_valid_after_ts'],
                $row['inactive_since_ts'] === null ? null : (int) $row['inactive_since_ts'],
                $row['inactive_reason'] === null ? null : InactiveReason::tryFrom($row['inactive_reason']),
                $this->openCallsOf($row['public_user_id']),
            );
    }
}
