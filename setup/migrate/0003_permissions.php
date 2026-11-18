<?php

declare(strict_types=1);

// Tabellen des Moduls hoelzle/php-permissions (Rollen, Rollenrechte, Konto-Rollen, Einzelrechte).
// Das Modul legt sie selbst mit CREATE TABLE IF NOT EXISTS an.
$createPermissionTables = require dirname(__DIR__, 2) . '/vendor/hoelzle/php-permissions/src/Setup/create_permissions.php';
$createPermissionTables();
