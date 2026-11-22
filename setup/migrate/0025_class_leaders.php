<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Klassenleitungen (GLOSSARY.md: Klassenleitung, Gueltigkeitszeitraum; ADR 0007, Ticket #68). Wie die Lehrkraft-Zuordnung zu
// Fachgruppen (Migration 0024) haengt die Klassenleitung vorerst am Konto (`account_id` = `accounts.public_user_id`), ohne
// Fremdschluessel: Rolle und Status des Kontos werden nur beim Anlegen geprueft, die Klassenleitung bleibt als Historie.
// Der Zeitraum steht als `valid_from` (erster Tag) und `valid_until` (exklusive Obergrenze); ohne `valid_until` ist das Ende
// offen und heisst "bis zum Ende des Schuljahres" der Klasse. Der Fremdschluessel auf die Klasse (ohne Kaskade) verhindert,
// dass eine Klasse mit Klassenleitung verschwindet. Gefahrlos wiederholbar (IF NOT EXISTS) und ohne Abhaengigkeit von der
// eigenen Nummer.
DB::getInstance()->exec(
    "CREATE TABLE IF NOT EXISTS `class_leaders` (
        `id` char(36) NOT NULL,
        `class_id` char(36) NOT NULL,
        `account_id` char(36) NOT NULL,
        `valid_from` date NOT NULL,
        `valid_until` date NULL DEFAULT NULL,
        `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (`id`),
        KEY `idx_class_leaders_class` (`class_id`, `valid_from`),
        KEY `idx_class_leaders_account` (`account_id`),
        CONSTRAINT `chk_class_leaders_period` CHECK (`valid_until` IS NULL OR `valid_until` > `valid_from`),
        CONSTRAINT `fk_class_leaders_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
