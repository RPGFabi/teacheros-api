<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Schuljahre der Schulinstanz (GLOSSARY.md: Schuljahr, Ticket #54). Beginn und Ende sind Kalendertage, beide inklusive.
// Bezeichnung und Status (laufend/kommend/vergangen) stehen bewusst nicht hier: die Bezeichnung ergibt sich aus dem
// Zeitraum, der Status aus dem heutigen Datum. Die Kennung (`id`, UUID) ist stabil; Klassen verweisen spaeter darauf.
// Ueberschneidungsfreiheit prueft der Service unter einer Sperre; der eindeutige Beginn ist nur ein zweites Netz.
// Gefahrlos wiederholbar (IF NOT EXISTS).
DB::getInstance()->exec(
    "CREATE TABLE IF NOT EXISTS `school_years` (
        `id` char(36) NOT NULL,
        `starts_on` date NOT NULL,
        `ends_on` date NOT NULL,
        `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_school_years_starts_on` (`starts_on`),
        KEY `idx_school_years_ends_on` (`ends_on`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
