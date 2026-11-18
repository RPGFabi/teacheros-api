<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;

/**
 * Berechnet `accounts.mfa_required` neu: das ODER ueber das Merkmal "Erfordert MFA" aller Rollen des
 * Kontos (ADR 0002 B4, Nachtrag #11). Ohne Rolle ist es aus. Rollen ohne Merkmale (nicht ueber die
 * Anwendung angelegt) gelten wie eigene Rollen als MFA-pflichtig. Laeuft in der Transaktion des Aufrufers.
 */
final class MfaRequirement
{
    /** @param list<string> $publicUserIds */
    public static function recomputeFor(array $publicUserIds): void
    {
        $publicUserIds = array_values(array_unique($publicUserIds));
        if ($publicUserIds === []) {
            return;
        }
        $marks = implode(',', array_fill(0, count($publicUserIds), '?'));
        DB::getInstance()->prepare(
            "UPDATE `accounts` SET `mfa_required` = EXISTS (
                SELECT 1 FROM `permission_user_roles` ur
                LEFT JOIN `role_settings` rs ON rs.`role_id` = ur.`role_id`
                WHERE ur.`user_uuid` = `accounts`.`public_user_id` AND COALESCE(rs.`requires_mfa`, 1) = 1
            ) WHERE `public_user_id` IN ($marks)"
        )->execute($publicUserIds);
    }
}
