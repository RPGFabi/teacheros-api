<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;

/** Schutzstufe und MFA-Merkmal der Rollen (Tabelle `role_settings`), neben den Tabellen des Pakets. */
final class RoleSettingsRepository
{
    /**
     * Haengt einer Rolle ihre Merkmale an. Hat sie schon welche, bleiben sie unveraendert.
     *
     * @return bool true nur, wenn dieser Aufruf die Zeile angelegt hat
     */
    public function registerIfMissing(string $roleId, RoleProtection $protection, bool $requiresMfa): bool
    {
        $stmt = DB::getInstance()->prepare(
            'INSERT IGNORE INTO `role_settings` (`role_id`, `protection`, `requires_mfa`) VALUES (:id, :protection, :mfa)'
        );
        $stmt->execute(['id' => $roleId, 'protection' => $protection->value, 'mfa' => (int) $requiresMfa]);
        return $stmt->rowCount() === 1;
    }

    /** @return array<string,array{protection:RoleProtection,requires_mfa:bool}> nach Rollen-ID */
    public function all(): array
    {
        $rows = DB::getInstance()->query('SELECT `role_id`, `protection`, `requires_mfa` FROM `role_settings`')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['role_id']] = [
                'protection' => RoleProtection::from($row['protection']),
                'requires_mfa' => (bool) $row['requires_mfa'],
            ];
        }
        return $result;
    }

    /** @return array{protection:RoleProtection,requires_mfa:bool}|null */
    public function find(string $roleId): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT `protection`, `requires_mfa` FROM `role_settings` WHERE `role_id` = :id');
        $stmt->execute(['id' => $roleId]);
        $row = $stmt->fetch();
        return $row === false ? null : ['protection' => RoleProtection::from($row['protection']), 'requires_mfa' => (bool) $row['requires_mfa']];
    }

    public function setRequiresMfa(string $roleId, bool $requiresMfa): void
    {
        DB::getInstance()->prepare('UPDATE `role_settings` SET `requires_mfa` = :mfa WHERE `role_id` = :id')
            ->execute(['id' => $roleId, 'mfa' => (int) $requiresMfa]);
    }
}
