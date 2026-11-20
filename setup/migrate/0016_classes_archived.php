<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Archivierung von Klassen (GLOSSARY.md: Archivierung, Ticket #57). `archived_at` ist NULL bei einer aktiven Klasse und
// der Zeitpunkt der Archivierung, solange die Klasse archiviert ist. Die Bezeichnung bleibt im Schuljahr belegt:
// der eindeutige Schluessel (school_year_id, name_key) aus Migration 0014 gilt unveraendert fuer archivierte Klassen.
// Gefahrlos wiederholbar (IF NOT EXISTS).
DB::getInstance()->exec('ALTER TABLE `classes` ADD COLUMN IF NOT EXISTS `archived_at` timestamp(6) NULL DEFAULT NULL AFTER `created_at`');
