<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Archivierung von Faechern (GLOSSARY.md: Archivierung, Ticket #63). `archived_at` ist NULL bei einem aktiven Fach und der
// Zeitpunkt der Archivierung, solange das Fach archiviert ist. Das Kuerzel bleibt belegt: der eindeutige Schluessel
// `code_key` aus Migration 0019 gilt unveraendert fuer archivierte Faecher.
// Gefahrlos wiederholbar (IF NOT EXISTS) und ohne Abhaengigkeit von der eigenen Nummer.
DB::getInstance()->exec('ALTER TABLE `subjects` ADD COLUMN IF NOT EXISTS `archived_at` timestamp(6) NULL DEFAULT NULL AFTER `created_at`');
