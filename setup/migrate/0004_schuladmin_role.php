<?php

declare(strict_types=1);

use Hoelzle\Permissions\UserPermissionService;

// System-Rolle Schuladmin mit dem Wildcard-Recht `*` (Spec #10, ADR 0002 B4).
// ensureSystemRole() legt sie nur an, wenn sie fehlt: gefahrlos wiederholbar.
(new UserPermissionService())->ensureSystemRole(
    'schuladmin',
    'Schuladmin',
    'Alle Rechte an der Schulinstanz, ausser dem Zugriff auf private ICE-Daten.'
);
