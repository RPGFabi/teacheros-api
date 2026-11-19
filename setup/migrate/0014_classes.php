<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Klassen der Schulinstanz (GLOSSARY.md: Klasse, Klassenstufe, Ticket #55). Eine Klasse gehoert unveraenderlich zu
// genau einem Schuljahr (`school_year_id`); "7a" im Folgejahr ist eine neue Klasse. Die Klassenstufe wird bei der Klasse
// festgehalten und nie aus der Bezeichnung gelesen. Die Bezeichnung ist je Schuljahr eindeutig ohne Ruecksicht auf Gross-/
// Kleinschreibung: `name_key` (kleingeschrieben, binaer verglichen) traegt den eindeutigen Schluessel, damit nur die
// Schreibung egal ist und etwa "7a" und "7ä" verschiedene Bezeichnungen bleiben. Der Fremdschluessel verhindert, dass ein
// Schuljahr mit Klassen verschwindet. Gefahrlos wiederholbar (IF NOT EXISTS).
DB::getInstance()->exec(
    "CREATE TABLE IF NOT EXISTS `classes` (
        `id` char(36) NOT NULL,
        `school_year_id` char(36) NOT NULL,
        `grade_level` tinyint unsigned NOT NULL,
        `name` varchar(20) NOT NULL,
        `name_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_classes_school_year_name` (`school_year_id`, `name_key`),
        CONSTRAINT `fk_classes_school_year` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
