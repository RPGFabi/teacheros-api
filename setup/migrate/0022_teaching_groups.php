<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Fachgruppen der Schulinstanz (GLOSSARY.md: Fachgruppe, Ticket #64). Eine Fachgruppe gehoert unveraenderlich zu genau einem
// Schuljahr und genau einem Fach; ihre Klassen (`teaching_group_classes`) sind eine Menge ohne Hauptklasse. Die Bezeichnung
// ist je Schuljahr eindeutig ohne Ruecksicht auf Gross-/Kleinschreibung: `name_key` (kleingeschrieben, binaer verglichen)
// traegt den eindeutigen Schluessel, damit nur die Schreibung egal ist. Die Fremdschluessel verhindern, dass ein Schuljahr,
// ein Fach oder eine Klasse mit Fachgruppen verschwindet; dass die Klassen zum Schuljahr der Fachgruppe gehoeren, prueft
// der Dienst. Gefahrlos wiederholbar (IF NOT EXISTS) und ohne Abhaengigkeit von der eigenen Nummer.
$db = DB::getInstance();
$db->exec(
    "CREATE TABLE IF NOT EXISTS `teaching_groups` (
        `id` char(36) NOT NULL,
        `school_year_id` char(36) NOT NULL,
        `subject_id` char(36) NOT NULL,
        `name` varchar(40) NOT NULL,
        `name_key` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_teaching_groups_school_year_name` (`school_year_id`, `name_key`),
        CONSTRAINT `fk_teaching_groups_school_year` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`),
        CONSTRAINT `fk_teaching_groups_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$db->exec(
    "CREATE TABLE IF NOT EXISTS `teaching_group_classes` (
        `teaching_group_id` char(36) NOT NULL,
        `class_id` char(36) NOT NULL,
        PRIMARY KEY (`teaching_group_id`, `class_id`),
        CONSTRAINT `fk_teaching_group_classes_group` FOREIGN KEY (`teaching_group_id`) REFERENCES `teaching_groups` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_teaching_group_classes_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
