<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;

/** Liest Konten bei jedem Aufruf frisch aus der Datenbank (kein Cache, ADR 0002 B1). */
final class AccountRepository
{
    public function findByPublicUserId(string $publicUserId): ?Account
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT `public_user_id`, `status`, `mfa_required` FROM `accounts` WHERE `public_user_id` = :id'
        );
        $stmt->execute(['id' => $publicUserId]);
        $row = $stmt->fetch();
        return $row === false ? null : new Account($row['public_user_id'], $row['status'], (bool) $row['mfa_required']);
    }

    public function create(string $publicUserId, string $status = 'invited'): void
    {
        DB::getInstance()
            ->prepare('INSERT INTO `accounts` (`public_user_id`, `status`) VALUES (:id, :status)')
            ->execute(['id' => $publicUserId, 'status' => $status]);
    }
}
