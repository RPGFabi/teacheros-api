<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Lehrkraft-Zuordnungen zu Fachgruppen (GLOSSARY.md: Lehrkraft-Zuordnung, Gueltigkeitszeitraum; ADR 0007, Ticket #67). Die
// Zuordnung haengt vorerst am Konto (`account_id` = `accounts.public_user_id`), ohne Fremdschluessel: Rolle und Status des
// Kontos werden nur beim Anlegen geprueft, die Zuordnung bleibt als Historie bestehen. Der Zeitraum steht nach der Source of
// Truth als `valid_from` (erster Tag) und `valid_until` (exklusive Obergrenze); ohne `valid_until` ist das Ende offen und
// heisst "bis zum Ende des Schuljahres" der Fachgruppe. Der Fremdschluessel auf die Fachgruppe (ohne Kaskade) verhindert, dass
// eine Fachgruppe mit Zuordnungen verschwindet. Gefahrlos wiederholbar (IF NOT EXISTS) und ohne Abhaengigkeit von der
// eigenen Nummer.
DB::getInstance()->exec(
    "CREATE TABLE IF NOT EXISTS `teacher_assignments` (
        `id` char(36) NOT NULL,
        `teaching_group_id` char(36) NOT NULL,
        `account_id` char(36) NOT NULL,
        `valid_from` date NOT NULL,
        `valid_until` date NULL DEFAULT NULL,
        `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (`id`),
        KEY `idx_teacher_assignments_group` (`teaching_group_id`, `valid_from`),
        KEY `idx_teacher_assignments_account` (`account_id`),
        CONSTRAINT `chk_teacher_assignments_period` CHECK (`valid_until` IS NULL OR `valid_until` > `valid_from`),
        CONSTRAINT `fk_teacher_assignments_group` FOREIGN KEY (`teaching_group_id`) REFERENCES `teaching_groups` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
