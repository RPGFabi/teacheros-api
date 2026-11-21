<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Markierung "MFA-Pflicht nicht gespiegelt" (ADR 0002 G2/B3, Ticket #15): 1, solange eine Aenderung von
// `accounts.mfa_required` dem Anmeldedienst noch nicht gemeldet ist (`mfa-requirement`). `MfaRequirement::recomputeFor`
// setzt sie in derselben Transaktion, `MfaRequirementSync` leert sie nach erfolgreichem Aufruf.
// Beim ersten Anlegen der Spalte gelten alle Pflichtkonten als offen: ihre Pflicht wurde vor #15 nie gespiegelt.
// Gefahrlos wiederholbar: die Nachmarkierung laeuft nur, wenn die Spalte neu entsteht.
$pdo = DB::getInstance();
$exists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'mfa_sync_pending'"
)->fetchColumn() > 0;
if (!$exists) {
    $pdo->exec('ALTER TABLE `accounts` ADD COLUMN `mfa_sync_pending` tinyint(1) NOT NULL DEFAULT 0 AFTER `mfa_required`');
    $pdo->exec('UPDATE `accounts` SET `mfa_sync_pending` = 1 WHERE `mfa_required` = 1');
}
