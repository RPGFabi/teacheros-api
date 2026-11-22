<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Faecher der Schulinstanz (GLOSSARY.md: Fach, Ticket #62). Ein Fach gilt fuer die ganze Schulinstanz, unabhaengig vom
// Schuljahr. Das Kuerzel (`code`) ist eindeutig und danach unveraenderlich; die Schreibung bleibt wie eingegeben, die
// Eindeutigkeit gilt ohne Ruecksicht auf Gross-/Kleinschreibung: `code_key` (kleingeschrieben, binaer verglichen) traegt den
// eindeutigen Schluessel, damit nur die Schreibung egal ist. Die Bezeichnung (`name`) muss nicht eindeutig sein.
// Gefahrlos wiederholbar (IF NOT EXISTS) und ohne Abhaengigkeit von der eigenen Nummer.
DB::getInstance()->exec(
    "CREATE TABLE IF NOT EXISTS `subjects` (
        `id` char(36) NOT NULL,
        `code` varchar(10) NOT NULL,
        `code_key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        `name` varchar(60) NOT NULL,
        `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_subjects_code_key` (`code_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
