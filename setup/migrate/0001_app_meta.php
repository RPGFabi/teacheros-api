<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

$pdo = DB::getInstance();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `app_meta` (
        `meta_key` varchar(64) NOT NULL,
        `meta_value` varchar(255) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`meta_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$pdo->exec("INSERT IGNORE INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('schema', 'teacheros')");
