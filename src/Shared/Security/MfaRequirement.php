<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;

/**
 * `accounts.mfa_required` ist das ODER ueber das Merkmal "Erfordert MFA" aller Rollen des Kontos
 * (ADR 0002 B4, Nachtrag #11). Wer Rollen oder dieses Merkmal aendert, ruft {@see self::recomputeFor()}
 * in derselben Transaktion auf. Eine Rolle ohne Merkmale zaehlt als "erfordert MFA" (sicherer Standard).
 */
final class MfaRequirement
{
    /** @param list<string> $publicUserIds */
    public static function recomputeFor(array $publicUserIds): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE `accounts` SET `mfa_required` = EXISTS (
                SELECT 1 FROM `permission_user_roles` ur
                LEFT JOIN `role_settings` rs ON rs.`role_id` = ur.`role_id`
                WHERE ur.`user_uuid` = `accounts`.`public_user_id` AND COALESCE(rs.`requires_mfa`, 1) = 1
            ) WHERE `public_user_id` = :id'
        );
        foreach (array_unique($publicUserIds) as $id) {
            $stmt->execute(['id' => $id]);
        }
    }
}
