<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Konto = Anmeldezugang einer Person (GLOSSARY.md). Der Status ist hier
// massgeblich (ADR 0002 B1); public_user_id ist das `sub` des Auth-Dienstes.
DB::getInstance()->exec(
    "CREATE TABLE IF NOT EXISTS `accounts` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `public_user_id` char(36) NOT NULL,
        `status` enum('invited','active','inactive') NOT NULL DEFAULT 'invited',
        `inactive_since` timestamp NULL DEFAULT NULL,
        `sessions_valid_after` timestamp NULL DEFAULT NULL,
        `mfa_required` tinyint(1) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_accounts_public_user_id` (`public_user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
