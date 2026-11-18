<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Einladungsdaten am Konto (Spec #10): E-Mail (nullbar, eindeutig) und Anmeldename
// (Pflicht, eindeutig) als lokale Kopie der Daten im Anmeldedienst, dazu "Eingeladen am".
// Gefahrlos wiederholbar (IF NOT EXISTS). Bereits vorhandene Konten ohne Anmeldenamen
// (Entwickler-Skript) bekommen ihre public_user_id als Anmeldenamen.
$pdo = DB::getInstance();
$pdo->exec('ALTER TABLE `accounts` ADD COLUMN IF NOT EXISTS `email` varchar(255) NULL DEFAULT NULL AFTER `public_user_id`');
$pdo->exec('ALTER TABLE `accounts` ADD COLUMN IF NOT EXISTS `login_name` varchar(255) NULL DEFAULT NULL AFTER `email`');
$pdo->exec('ALTER TABLE `accounts` ADD COLUMN IF NOT EXISTS `invited_at` timestamp NULL DEFAULT NULL AFTER `status`');
$pdo->exec('UPDATE `accounts` SET `login_name` = `public_user_id` WHERE `login_name` IS NULL');
$pdo->exec('ALTER TABLE `accounts` MODIFY COLUMN `login_name` varchar(255) NOT NULL');
$pdo->exec('ALTER TABLE `accounts` ADD UNIQUE INDEX IF NOT EXISTS `uq_accounts_email` (`email`)');
$pdo->exec('ALTER TABLE `accounts` ADD UNIQUE INDEX IF NOT EXISTS `uq_accounts_login_name` (`login_name`)');
