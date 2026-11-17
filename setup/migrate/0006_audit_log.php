<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Audit-Eintrag (GLOSSARY.md): wer, wann, welche Handlung, an welchem Objekt. Keine
// personenbezogenen Inhalte; `context` nimmt nur technische Angaben auf. Konten werden
// ueber ihre public_user_id referenziert (stabil, bleibt auch nach Loeschung eindeutig).
DB::getInstance()->exec(
    "CREATE TABLE IF NOT EXISTS `audit_log` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `occurred_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        `actor_type` enum('account','cli') NOT NULL,
        `actor_account_id` char(36) NULL DEFAULT NULL,
        `action` varchar(64) NOT NULL,
        `object_type` varchar(64) NOT NULL,
        `object_id` varchar(64) NOT NULL,
        `context` json NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_audit_log_object` (`object_type`, `object_id`),
        KEY `idx_audit_log_occurred_at` (`occurred_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
