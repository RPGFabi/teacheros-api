<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;

/**
 * Die sechs Standardrollen jeder Schulinstanz (GLOSSARY.md, ADR 0002 B4 Nachtrag #11):
 * Schluessel, Name, Schutzstufe und festes MFA-Merkmal. Die Reihenfolge der Liste ist die
 * Anzeigereihenfolge; {@see self::position()} sortiert eigene Rollen dahinter ein.
 */
final class StandardRoles
{
    public const SCHULADMIN = 'schuladmin';
    public const SCHUELER = 'schueler';
    public const LEHRKRAFT = 'lehrkraft';
    public const ERZIEHUNGSBERECHTIGTE = 'erziehungsberechtigte';
    public const SEKRETARIAT = 'sekretariat';
    public const SCHULLEITUNG = 'schulleitung';

    public function __construct(
        private readonly AuditLog $audit = new AuditLog(),
        private readonly RoleSettingsRepository $settings = new RoleSettingsRepository(),
    ) {
    }

    /** @return list<array{key:string,name:string,description:string,protection:RoleProtection,requires_mfa:bool}> */
    public static function definitions(): array
    {
        return [
            ['key' => self::SCHULADMIN, 'name' => 'Schuladmin', 'protection' => RoleProtection::Wildcard, 'requires_mfa' => true,
                'description' => 'Alle Rechte an der Schulinstanz, außer dem Zugriff auf private ICE-Daten.'],
            ['key' => self::SCHUELER, 'name' => 'Schüler/in', 'protection' => RoleProtection::Protected, 'requires_mfa' => false,
                'description' => 'Schülerinnen und Schüler. Die Rechte dieser Rolle liegen fest und sind nicht änderbar.'],
            ['key' => self::LEHRKRAFT, 'name' => 'Lehrkraft', 'protection' => RoleProtection::Standard, 'requires_mfa' => true,
                'description' => 'Lehrkräfte. Die Rechte legt die Schule fest.'],
            ['key' => self::ERZIEHUNGSBERECHTIGTE, 'name' => 'Erziehungsberechtigte/r', 'protection' => RoleProtection::Standard, 'requires_mfa' => false,
                'description' => 'Erziehungsberechtigte. Die Rechte legt die Schule fest.'],
            ['key' => self::SEKRETARIAT, 'name' => 'Sekretariat', 'protection' => RoleProtection::Standard, 'requires_mfa' => true,
                'description' => 'Sekretariat. Die Rechte legt die Schule fest.'],
            ['key' => self::SCHULLEITUNG, 'name' => 'Schulleitung', 'protection' => RoleProtection::Standard, 'requires_mfa' => true,
                'description' => 'Schulleitung. Die Rechte legt die Schule fest.'],
        ];
    }

    /** Platz in der Anzeigereihenfolge; eigene Rollen (kein Standardschluessel) kommen nach allen Standardrollen. */
    public static function position(string $roleKey): int
    {
        foreach (self::definitions() as $i => $definition) {
            if ($definition['key'] === $roleKey) {
                return $i;
            }
        }
        return PHP_INT_MAX;
    }

    /**
     * Legt fehlende Standardrollen samt Merkmalen an. Bestehende Rollen bleiben unveraendert, damit
     * Aenderungen der Schule (Rechte, Name, Beschreibung) ein erneutes Setup überstehen. Nur wenn die
     * Merkmale dabei neu entstehen, gibt es einen Audit-Eintrag `role.created`.
     */
    public function ensureAll(Actor $actor): void
    {
        $service = PermissionCatalog::service();
        $pdo = DB::getInstance();
        foreach (self::definitions() as $d) {
            $pdo->beginTransaction();
            try {
                $roleId = $d['protection'] === RoleProtection::Wildcard
                    ? $service->ensureSystemRole($d['key'], $d['name'], $d['description'])
                    : $service->ensureRole($d['key'], $d['name'], $d['description'], []);
                if ($this->settings->registerIfMissing($roleId, $d['protection'], $d['requires_mfa'])) {
                    $this->audit->record($actor, AuditLog::ROLE_CREATED, 'role', $roleId, ['role' => $d['key'], 'name' => $d['name']]);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }
}
