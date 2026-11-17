<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;

/**
 * Der einzige Weg, Rollen zu vergeben oder zu entziehen (ADR 0002 B4); jede Aktion
 * erzeugt einen Audit-Eintrag. Minimal: #11 baut Rechtepruefung und Invarianten aus.
 * Laeuft in der Transaktion des Aufrufers mit (PermissionRepository::transaction verschachtelt).
 */
final class AccessAdministration
{
    public const ROLE_SCHULADMIN = 'schuladmin';

    public function __construct(private readonly AuditLog $audit = new AuditLog())
    {
    }

    /** Vergibt die Rolle Schuladmin. Hat das Konto sie schon, geschieht nichts (kein doppelter Audit-Eintrag). */
    public function grantInitialAdmin(string $publicUserId, Actor $actor): void
    {
        $service = PermissionCatalog::service();
        $roleId = $service->ensureSystemRole(self::ROLE_SCHULADMIN, 'Schuladmin');
        if (in_array($roleId, $service->getUserAccess($publicUserId)['role_ids'], true)) {
            return;
        }
        $service->assignRole($publicUserId, $roleId);
        $this->audit->record($actor, AuditLog::ROLE_ASSIGNED, 'account', $publicUserId, ['role' => self::ROLE_SCHULADMIN]);
    }

    /** Entzieht einem Konto alle Rollen (z. B. wenn eine nie angenommene Einladung ersetzt wird). */
    public function revokeAllRoles(string $publicUserId, Actor $actor): void
    {
        $service = PermissionCatalog::service();
        $access = $service->getUserAccess($publicUserId);
        if ($access['role_ids'] === [] && $access['overrides'] === []) {
            return;
        }
        $service->setUserAccess($publicUserId, [], []);
        $this->audit->record($actor, AuditLog::ROLE_REVOKED, 'account', $publicUserId);
    }
}
