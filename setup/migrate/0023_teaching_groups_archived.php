<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Archivierung von Fachgruppen (GLOSSARY.md: Archivierung, Ticket #65). `archived_at` ist NULL bei einer aktiven Fachgruppe
// und der Zeitpunkt der Archivierung, solange sie archiviert ist. Die Bezeichnung bleibt im Schuljahr belegt: der
// eindeutige Schluessel (school_year_id, name_key) aus Migration 0022 gilt unveraendert fuer archivierte Fachgruppen.
// Gefahrlos wiederholbar (IF NOT EXISTS) und ohne Abhaengigkeit von der eigenen Nummer.
DB::getInstance()->exec('ALTER TABLE `teaching_groups` ADD COLUMN IF NOT EXISTS `archived_at` timestamp(6) NULL DEFAULT NULL AFTER `created_at`');
