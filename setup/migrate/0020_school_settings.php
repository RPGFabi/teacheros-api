<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Schuleinstellungen der Schulinstanz (GLOSSARY.md: Schuleinstellung, Ticket #66): je Einstellung eine Zeile mit
// Schluessel und ganzzahligem Wert, gleich fuer alle Schuljahre. Fehlt die Zeile, gilt der Standardwert im Code
// (SchoolSettingKey); es wird nichts vorbelegt. Gefahrlos wiederholbar (IF NOT EXISTS).
DB::getInstance()->exec(
    "CREATE TABLE IF NOT EXISTS `school_settings` (
        `setting_key` varchar(64) NOT NULL,
        `value` int NOT NULL,
        `updated_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
