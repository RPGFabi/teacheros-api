<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * P3 "Letzter Schuladmin bleibt erhalten" (ADR 0002 B4): Eine Aktion darf der Schulinstanz nie ihr letztes
 * Konto mit Status `active` und der Rolle Schuladmin nehmen. Nur eingeladene oder deaktivierte Admins zaehlen nicht.
 *
 * Sperrstrategie (wenn gesperrt wird, also beim Ausfuehren): Die Zeile der Rolle Schuladmin in `permission_roles`
 * ist der Mutex ueber den Kreis der Schuladmins; wer ihn aendert, sperrt sie zuerst (`FOR UPDATE`). Danach liest die
 * Pruefung die aktiven Admins mit `FOR UPDATE` (sieht also den neuesten Stand, auch in einer schon laufenden
 * Transaktion). Wichtig fuer Aufrufer: Mutex zuerst, Zielkonto danach (siehe {@see AccessAdministration::ensureAdminRemains()}),
 * sonst drohen Deadlocks zwischen zwei Admins, die einander gleichzeitig bearbeiten.
 */
final class LastAdminGuard
{
    /**
     * Warum die Konten $leavingIds nicht alle aus dem Kreis der aktiven Schuladmins ausscheiden duerfen, sonst null.
     * Konten, die gar kein aktiver Schuladmin sind, aendern den Bestand nicht und loesen nie eine Ablehnung aus.
     *
     * @param list<string> $leavingIds public_user_ids der Konten, die Rolle oder aktiven Status verlieren
     * @param bool $lock true: Mutex und Admin-Zeilen sperren (nur innerhalb einer Transaktion sinnvoll); false: reine Lesesicht
     */
    public function refusal(array $leavingIds, bool $lock = false): ?CodedException
    {
        if ($lock) {
            self::lockMutex();
        }
        $active = $this->activeAdminIds($lock);
        $leaving = array_intersect($active, $leavingIds);
        if ($leaving !== [] && array_diff($active, $leaving) === []) {
            return new CodedException('last_admin', 409, 'Das ist der letzte aktive Schuladmin. Ernennen Sie zuerst einen weiteren Schuladmin, der sich schon angemeldet hat.');
        }
        return null;
    }

    /** Sperrt den Mutex (Zeile der Rolle Schuladmin); in derselben Transaktion ein No-Op. */
    public static function lockMutex(): void
    {
        $stmt = DB::getInstance()->prepare('SELECT `id` FROM `permission_roles` WHERE `role_key` = :role FOR UPDATE');
        $stmt->execute(['role' => AccessAdministration::ROLE_SCHULADMIN]);
    }

    /** @return list<string> */
    private function activeAdminIds(bool $lock): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT a.`public_user_id` FROM `accounts` a
             JOIN `permission_user_roles` ur ON ur.`user_uuid` = a.`public_user_id`
             JOIN `permission_roles` r ON r.`id` = ur.`role_id`
             WHERE r.`role_key` = :role AND a.`status` = :status ORDER BY a.`id`' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['role' => AccessAdministration::ROLE_SCHULADMIN, 'status' => AccountStatus::Active->value]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
