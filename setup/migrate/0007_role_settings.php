<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Merkmale der Rollen, die das Paket hoelzle/php-permissions nicht kennt (ADR 0002 B4, Nachtrag #11):
// die Schutzstufe und "Erfordert MFA". Eine Zeile je Rolle, mit der Rolle zusammen geloescht.
//   protection: wildcard  = alle Rechte, fest (Schuladmin)
//               protected = Rechte kommen aus dem Code, nicht aenderbar (Schueler/in)
//               standard  = Standardrolle: Rechte frei aenderbar, nicht loeschbar
//               custom    = selbst angelegt, frei aenderbar und loeschbar
//   requires_mfa: bei Standardrollen fest, bei eigenen Rollen abschaltbar.
DB::getInstance()->exec(
    "CREATE TABLE IF NOT EXISTS `role_settings` (
        `role_id` char(36) NOT NULL,
        `protection` enum('wildcard','protected','standard','custom') NOT NULL,
        `requires_mfa` tinyint(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`role_id`),
        CONSTRAINT `fk_role_settings_role` FOREIGN KEY (`role_id`) REFERENCES `permission_roles` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
