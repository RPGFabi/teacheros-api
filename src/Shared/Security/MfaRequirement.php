<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;

/**
 * `accounts.mfa_required` ist das ODER ueber das Merkmal "Erfordert MFA" aller Rollen des Kontos
 * (ADR 0002 B4, Nachtrag #11). Wer Rollen oder dieses Merkmal aendert, ruft {@see self::recomputeFor()}
 * in derselben Transaktion auf. Eine Rolle ohne Merkmale zaehlt als "erfordert MFA" (sicherer Standard).
 *
 * Aendert sich der Wert, markiert die Neuberechnung das Konto als "nicht gespiegelt" (`mfa_sync_pending`, Ticket #15);
 * {@see MfaRequirementSync} meldet es nach dem Commit an den Anmeldedienst (G2). Durchgesetzt wird die Pflicht lokal (B3),
 * unabhaengig davon, ob die Spiegelung gelingt.
 */
final class MfaRequirement
{
    private const REQUIRED = 'EXISTS (
        SELECT 1 FROM `permission_user_roles` ur
        LEFT JOIN `role_settings` rs ON rs.`role_id` = ur.`role_id`
        WHERE ur.`user_uuid` = `accounts`.`public_user_id` AND COALESCE(rs.`requires_mfa`, 1) = 1
    )';

    /** @param list<string> $publicUserIds */
    public static function recomputeFor(array $publicUserIds): void
    {
        // Die Markierung steht zuerst und vergleicht mit dem alten Wert (auch mit SIMULTANEOUS_ASSIGNMENT richtig).
        $stmt = DB::getInstance()->prepare(
            'UPDATE `accounts` SET
                `mfa_sync_pending` = IF(`mfa_required` <> ' . self::REQUIRED . ', 1, `mfa_sync_pending`),
                `mfa_required` = ' . self::REQUIRED . '
            WHERE `public_user_id` = :id'
        );
        foreach (array_unique($publicUserIds) as $id) {
            $stmt->execute(['id' => $id]);
        }
    }
}
