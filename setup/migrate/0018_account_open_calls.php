<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Offene Aufrufe an den Anmeldedienst je Konto (ADR 0002 B2, Nachtrag #16, Ticket #47): ersetzt die Markierung
// "Abgleich offen" (`accounts.login_sync_pending`) und den Ablehnungscode (`accounts.login_sync_error`) durch eine Menge.
// Eine Zeile je Konto und Aufruf (`operation`, z. B. `disable_login`; weitere kommen mit Widerruf und Zuruecksetzung ohne
// Schemaaenderung), `rejection_code` ist der Code, mit dem der Dienst zuletzt fachlich abgelehnt hat (NULL: noch nicht
// versucht oder nicht erreichbar). Die Reihenfolge des Nachholens legt die Registry im Code fest, nicht die Tabelle.
//
// Expand-Schritt nach ADR 0004 (Expand/Contract): die alten Spalten `login_sync_pending` und `login_sync_error` bleiben, weil das
// vorige Backend sie noch liest; das neue Backend liest und schreibt sie nicht mehr (sie sind ein veralteter Stand). TODO: eine spaetere
// Contract-Migration entfernt sie, sobald kein Backend mehr laeuft, das sie liest.
// Gefahrlos wiederholbar: die Uebernahme der alten Spalten laeuft nur im Lauf, der die Tabelle anlegt. Eine Wiederholung danach
// liesse sonst erledigte Aufrufe wieder aufleben, weil die alten Spalten nicht mehr nachgefuehrt werden.
$pdo = DB::getInstance();
$tableExisted = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_open_calls'"
)->fetchColumn() > 0;

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `account_open_calls` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `account_id` char(36) NOT NULL,
        `operation` varchar(32) NOT NULL,
        `rejection_code` varchar(64) NULL DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_account_open_calls` (`account_id`, `operation`),
        CONSTRAINT `fk_account_open_calls_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`public_user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$hasColumn = static fn (string $column): bool => (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = '$column'"
)->fetchColumn() > 0;

if (!$tableExisted && $hasColumn('login_sync_pending')) {
    $error = $hasColumn('login_sync_error') ? '`login_sync_error`' : 'NULL';
    $pdo->exec(
        "INSERT IGNORE INTO `account_open_calls` (`account_id`, `operation`, `rejection_code`)
         SELECT `public_user_id`, 'disable_login', $error FROM `accounts` WHERE `status` = 'inactive' AND `login_sync_pending` = 1"
    );
}
