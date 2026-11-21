<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Modulfreigaben der Schulinstanz (ADR 0002 B5, Ticket #13). Lokal gespeichert, damit ein Ausfall der Control Plane den
// Betrieb nicht stoppt (Source of Truth 17.12). Nur anfuegen, nie aendern: jede Zeile ist eine Freigabe-Version mit
// Gueltigkeitsbeginn (`valid_from`, UTC). Gueltig ist je Modul die Zeile mit dem spaetesten `valid_from` <= jetzt
// (bei Gleichstand die spaeter angelegte). Ohne Zeile ist ein Modul nicht freigegeben. `core` steht nie hier: es ist
// immer aktiv. Gefahrlos wiederholbar (IF NOT EXISTS).
DB::getInstance()->exec(
    "CREATE TABLE IF NOT EXISTS `module_entitlements` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `module_id` varchar(32) NOT NULL,
        `enabled` tinyint(1) NOT NULL,
        `valid_from` datetime(6) NOT NULL,
        `recorded_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (`id`),
        KEY `idx_module_entitlements_module` (`module_id`, `valid_from`, `id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
