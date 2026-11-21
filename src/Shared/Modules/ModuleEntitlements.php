<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Modules;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Die lokal gespeicherten Modulfreigaben der Schulinstanz (ADR 0002 B5, Source of Truth 17.12).
 *
 * Freigaben sind zeitlich versioniert: jede Aenderung ist eine neue Zeile mit Gueltigkeitsbeginn, nichts wird
 * ueberschrieben. Eine Freigabe sagt nur, ob ein Modul an dieser Schulinstanz laeuft; sie gewaehrt nie selbst ein
 * Recht oder Zugriff auf Objekte. Zeiten sind UTC.
 */
final class ModuleEntitlements
{
    private const FORMAT = 'Y-m-d H:i:s.u';

    public function isActive(string $moduleId, ?\DateTimeImmutable $at = null): bool
    {
        if (!ModuleCatalog::exists($moduleId)) {
            return false;
        }
        if ($moduleId === ModuleCatalog::CORE) {
            return true;
        }
        $stmt = DB::getInstance()->prepare(
            'SELECT `enabled` FROM `module_entitlements` WHERE `module_id` = :module AND `valid_from` <= :at
             ORDER BY `valid_from` DESC, `id` DESC LIMIT 1'
        );
        $stmt->execute(['module' => $moduleId, 'at' => self::utc($at ?? self::now())]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Die zum Zeitpunkt aktiven Module in Katalogreihenfolge, `core` immer zuerst.
     *
     * @return list<string>
     */
    public function active(?\DateTimeImmutable $at = null): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT e.`module_id`, e.`enabled` FROM `module_entitlements` e
             WHERE e.`id` = (
                 SELECT l.`id` FROM `module_entitlements` l
                 WHERE l.`module_id` = e.`module_id` AND l.`valid_from` <= :at
                 ORDER BY l.`valid_from` DESC, l.`id` DESC LIMIT 1
             )'
        );
        $stmt->execute(['at' => self::utc($at ?? self::now())]);
        $enabled = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['enabled'] === 1) {
                $enabled[$row['module_id']] = true;
            }
        }
        return array_values(array_filter(
            ModuleCatalog::all(),
            static fn (string $id): bool => $id === ModuleCatalog::CORE || isset($enabled[$id]),
        ));
    }

    /**
     * Gibt ein Modul ab `$validFrom` frei oder nimmt die Freigabe zurueck, als neue Version mit Audit-Eintrag in einer
     * Transaktion. Der Gueltigkeitsbeginn darf nicht in der Vergangenheit liegen: frueher berechnete Ergebnisse
     * bleiben reproduzierbar (Source of Truth 17.12).
     *
     * @throws CodedException `unknown_module` (404), `core_always_active` (409), `valid_from_in_past` (422),
     *                        `already_enabled` / `already_disabled` (409)
     */
    public function change(string $moduleId, bool $enabled, Actor $actor, ?\DateTimeImmutable $validFrom = null): \DateTimeImmutable
    {
        if (!ModuleCatalog::exists($moduleId)) {
            throw new CodedException('unknown_module', 404, "Unbekanntes Modul: {$moduleId}");
        }
        if ($moduleId === ModuleCatalog::CORE) {
            throw new CodedException('core_always_active', 409, 'Das Modul core ist immer aktiv und kann nicht geändert werden.');
        }
        $now = self::now();
        $validFrom = ($validFrom ?? $now)->setTimezone(new \DateTimeZone('UTC'));
        if ($validFrom < $now->modify('-1 minute')) {
            throw new CodedException('valid_from_in_past', 422, 'Der Gültigkeitsbeginn darf nicht in der Vergangenheit liegen.');
        }

        $pdo = DB::getInstance();
        $pdo->beginTransaction();
        try {
            // Sperrt die Versionen dieses Moduls, damit zwei gleichzeitige Aenderungen nicht beide als "Aenderung" gelten.
            $pdo->prepare('SELECT `id` FROM `module_entitlements` WHERE `module_id` = ? FOR UPDATE')->execute([$moduleId]);
            if ($this->isActive($moduleId, $validFrom) === $enabled) {
                throw new CodedException($enabled ? 'already_enabled' : 'already_disabled', 409, $enabled
                    ? "Das Modul {$moduleId} ist zu diesem Zeitpunkt bereits freigegeben."
                    : "Das Modul {$moduleId} ist zu diesem Zeitpunkt bereits nicht freigegeben.");
            }
            $pdo->prepare('INSERT INTO `module_entitlements` (`module_id`, `enabled`, `valid_from`) VALUES (?, ?, ?)')
                ->execute([$moduleId, $enabled ? 1 : 0, self::utc($validFrom)]);
            (new AuditLog())->record(
                $actor,
                $enabled ? AuditLog::MODULE_ENABLED : AuditLog::MODULE_DISABLED,
                'module',
                $moduleId,
                ['valid_from' => $validFrom->format('Y-m-d\TH:i:s\Z')],
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $validFrom;
    }

    /**
     * Alle Versionen eines Moduls, aelteste zuerst (Historie).
     *
     * @return list<array{enabled:bool,valid_from:string,recorded_at:string}>
     */
    public function history(string $moduleId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT `enabled`, `valid_from`, `recorded_at` FROM `module_entitlements` WHERE `module_id` = ? ORDER BY `valid_from`, `id`'
        );
        $stmt->execute([$moduleId]);
        return array_map(static fn (array $row): array => [
            'enabled' => (int) $row['enabled'] === 1,
            'valid_from' => (string) $row['valid_from'],
            'recorded_at' => (string) $row['recorded_at'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
    }
}
