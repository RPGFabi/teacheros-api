<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Grund der Deaktivierung am Konto (ADR 0002 B2, Nachtrag #12): V1 kennt nur `admin_locked`. NULL bei aktiven und
// eingeladenen Konten; die Reaktivierung entfernt ihn wieder. Gefahrlos wiederholbar (IF NOT EXISTS).
DB::getInstance()->exec('ALTER TABLE `accounts` ADD COLUMN IF NOT EXISTS `inactive_reason` varchar(32) NULL DEFAULT NULL AFTER `inactive_since`');
