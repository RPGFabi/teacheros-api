<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;
use Hoelzle\Permissions\UserPermissionService;

// Die sechs Standardrollen jeder Schulinstanz (GLOSSARY.md, ADR 0002 B4 Nachtrag #11), idempotent.
// Eine Migration ist eine Momentaufnahme: Die Definitionen stehen deshalb fest hier und nicht im
// Anwendungscode, der sich spaeter aendern darf. Bestehende Rollen bleiben unveraendert (Aenderungen der
// Schule ueberstehen ein erneutes Setup); fehlen ihnen nur die Merkmale in `role_settings`, werden die
// nachgetragen. `role.created` (Akteur `cli`) gibt es nur, wenn die Rolle selbst neu angelegt wurde.
$definitions = [
    ['key' => 'schuladmin', 'name' => 'Schuladmin', 'protection' => 'wildcard', 'requires_mfa' => 1,
        'description' => 'Alle Rechte an der Schulinstanz, außer dem Zugriff auf private ICE-Daten.'],
    ['key' => 'schueler', 'name' => 'Schüler/in', 'protection' => 'protected', 'requires_mfa' => 0,
        'description' => 'Schülerinnen und Schüler. Die Rechte dieser Rolle liegen fest und sind nicht änderbar.'],
    ['key' => 'lehrkraft', 'name' => 'Lehrkraft', 'protection' => 'standard', 'requires_mfa' => 1,
        'description' => 'Lehrkräfte. Die Rechte legt die Schule fest.'],
    ['key' => 'erziehungsberechtigte', 'name' => 'Erziehungsberechtigte/r', 'protection' => 'standard', 'requires_mfa' => 0,
        'description' => 'Erziehungsberechtigte. Die Rechte legt die Schule fest.'],
    ['key' => 'sekretariat', 'name' => 'Sekretariat', 'protection' => 'standard', 'requires_mfa' => 1,
        'description' => 'Sekretariat. Die Rechte legt die Schule fest.'],
    ['key' => 'schulleitung', 'name' => 'Schulleitung', 'protection' => 'standard', 'requires_mfa' => 1,
        'description' => 'Schulleitung. Die Rechte legt die Schule fest.'],
];

$pdo = DB::getInstance();
$service = new UserPermissionService();
$exists = $pdo->prepare('SELECT `id` FROM `permission_roles` WHERE `role_key` = :key');
$settings = $pdo->prepare('INSERT IGNORE INTO `role_settings` (`role_id`, `protection`, `requires_mfa`) VALUES (:id, :protection, :mfa)');
$audit = $pdo->prepare(
    "INSERT INTO `audit_log` (`actor_type`, `actor_account_id`, `action`, `object_type`, `object_id`, `context`)
     VALUES ('cli', NULL, 'role.created', 'role', :id, :context)"
);

foreach ($definitions as $d) {
    $pdo->beginTransaction();
    try {
        $exists->execute(['key' => $d['key']]);
        $roleId = $exists->fetchColumn();
        $created = $roleId === false;
        if ($created) {
            $roleId = $d['protection'] === 'wildcard'
                ? $service->ensureSystemRole($d['key'], $d['name'], $d['description'])
                : $service->ensureRole($d['key'], $d['name'], $d['description'], []);
        }
        $settings->execute(['id' => $roleId, 'protection' => $d['protection'], 'mfa' => $d['requires_mfa']]);
        if ($created) {
            $audit->execute(['id' => $roleId, 'context' => json_encode(['role' => $d['key'], 'name' => $d['name']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
