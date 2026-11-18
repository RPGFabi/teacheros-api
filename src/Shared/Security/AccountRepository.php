<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;

/** Liest Konten bei jedem Aufruf frisch aus der Datenbank (kein Cache, ADR 0002 B1). */
final class AccountRepository
{
    private const COLUMNS = '`public_user_id`, `status`, `mfa_required`, `email`, `login_name`, `inactive_reason`, `login_sync_pending`,
        UNIX_TIMESTAMP(`sessions_valid_after`) AS sessions_valid_after_ts, UNIX_TIMESTAMP(`inactive_since`) AS inactive_since_ts';

    public function findByPublicUserId(string $publicUserId): ?Account
    {
        return $this->findOne('`public_user_id` = :v', $publicUserId);
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
     * ("Abgleich offen", Ticket #42; nur bei deaktivierten Konten true).
     *
     * @return list<array{id:string,email:?string,login_name:string,status:string,invited_at:?string,inactive_since:?string,login_sync_pending:bool}>
     */
    public function listAll(): array
    {
        $rows = DB::getInstance()->query(
            'SELECT `public_user_id`, `email`, `login_name`, `status`, UNIX_TIMESTAMP(`invited_at`) AS invited_ts, UNIX_TIMESTAMP(`inactive_since`) AS inactive_ts, `login_sync_pending` FROM `accounts` ORDER BY `id`'
        )->fetchAll();
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
     * Deaktiviert ein aktives Konto: Status, Grund, `inactive_since`, `sessions_valid_after` (ADR 0002 B2) und die
     * Markierung "Abgleich offen" (Ticket #42) in einem Schritt. Der Aufrufer prueft Regeln und sperrt in seiner Transaktion.
     *
     * @return bool true nur, wenn dieser Aufruf den Wechsel vollzogen hat
     */
    public function deactivate(string $publicUserId, InactiveReason $reason): bool
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE `accounts` SET `status` = :inactive, `inactive_reason` = :reason, `inactive_since` = CURRENT_TIMESTAMP, `sessions_valid_after` = CURRENT_TIMESTAMP,
                `login_sync_pending` = 1
             WHERE `public_user_id` = :id AND `status` = :active'
        );
        $stmt->execute(['id' => $publicUserId, 'inactive' => AccountStatus::Inactive->value, 'reason' => $reason->value, 'active' => AccountStatus::Active->value]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Reaktiviert ein deaktiviertes Konto: Status `active`, Grund, `inactive_since` und die Markierung "Abgleich offen" entfallen.
     * `sessions_valid_after` bleibt, alte Tokens bleiben ungueltig (ADR 0002 B2). Der Aufrufer prueft Regeln, hat den
     * Anmeldedienst unterrichtet und sperrt in seiner Transaktion.
     *
     * @return bool true nur, wenn dieser Aufruf den Wechsel vollzogen hat
     */
    public function reactivate(string $publicUserId): bool
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE `accounts` SET `status` = :active, `inactive_reason` = NULL, `inactive_since` = NULL, `login_sync_pending` = 0
             WHERE `public_user_id` = :id AND `status` = :inactive'
        );
        $stmt->execute(['id' => $publicUserId, 'active' => AccountStatus::Active->value, 'inactive' => AccountStatus::Inactive->value]);
        return $stmt->rowCount() === 1;
    }

    /** Leert die Markierung "Abgleich offen" eines deaktivierten Kontos (der Anmeldedienst ist unterrichtet). */
    public function markLoginSynced(string $publicUserId): void
    {
        DB::getInstance()->prepare('UPDATE `accounts` SET `login_sync_pending` = 0 WHERE `public_user_id` = :id')
            ->execute(['id' => $publicUserId]);
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
                $row['inactive_reason'],
                (bool) $row['login_sync_pending'],
            );
    }
}
