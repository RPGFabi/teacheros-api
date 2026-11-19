<?php

declare(strict_types=1);

// Modulfreigaben der Schulinstanz verwalten (ADR 0002 B5, Ticket #13). Bis es die Control Plane gibt, setzt der
// Betreiber der Installation die Freigaben hier (Source of Truth 4.5); die Schule selbst kann sie nicht aendern.
//   php bin/module.php list
//   php bin/module.php enable  <modul> [--valid-from=2026-12-01T00:00:00+01:00]
//   php bin/module.php disable <modul> [--valid-from=...]
//   php bin/module.php history <modul>
// Ohne --valid-from gilt die Aenderung sofort. Jede Aenderung ist eine neue Version mit Audit-Eintrag (Akteur: cli).
// Exit-Codes: 0 ok, 2 Aufruf falsch, 3 fachlich abgelehnt (unbekanntes Modul, core, Beginn in der Vergangenheit,
// keine Aenderung).

use Hoelzle\Core\Application;
use Hoelzle\Core\Config\Config;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Modules\ModuleCatalog;
use TeacherOS\Shared\Modules\ModuleEntitlements;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Application::initialize(dirname(__DIR__));
Config::load();

$usage = "Aufruf: php bin/module.php list | enable <modul> [--valid-from=<ISO-8601>] | disable <modul> [--valid-from=<ISO-8601>] | history <modul>\n";

$positional = [];
$validFrom = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--valid-from=')) {
        try {
            $validFrom = new DateTimeImmutable(substr($arg, strlen('--valid-from=')));
        } catch (Exception) {
            fwrite(STDERR, "Ungueltiger Zeitpunkt: {$arg}\n" . $usage);
            exit(2);
        }
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unbekannte Option: {$arg}\n" . $usage);
        exit(2);
    } else {
        $positional[] = $arg;
    }
}

$command = $positional[0] ?? null;
$module = $positional[1] ?? null;
$entitlements = new ModuleEntitlements();

if ($command === 'list' && $module === null && $validFrom === null) {
    $active = $entitlements->active();
    foreach (ModuleCatalog::entries() as $entry) {
        $state = in_array($entry['id'], $active, true) ? 'aktiv' : 'inaktiv';
        echo str_pad($entry['id'], 12) . str_pad($state, 9) . $entry['name'] . "\n";
    }
    exit(0);
}

if ($command === 'history' && $module !== null && $validFrom === null) {
    if (!ModuleCatalog::exists($module)) {
        fwrite(STDERR, "Abgelehnt (unknown_module): Unbekanntes Modul: {$module}\n");
        exit(3);
    }
    foreach ($entitlements->history($module) as $version) {
        echo "ab {$version['valid_from']} UTC  " . ($version['enabled'] ? 'freigegeben' : 'nicht freigegeben') . "  (erfasst {$version['recorded_at']})\n";
    }
    exit(0);
}

if (in_array($command, ['enable', 'disable'], true) && $module !== null && count($positional) === 2) {
    try {
        $from = $entitlements->change($module, $command === 'enable', Actor::cli(), $validFrom);
    } catch (CodedException $e) {
        fwrite(STDERR, "Abgelehnt ({$e->errorCode}): {$e->getMessage()}\n");
        exit(3);
    }
    echo ($command === 'enable' ? 'Freigegeben' : 'Freigabe zurueckgenommen') . ": {$module} ab {$from->format('Y-m-d H:i:s')} UTC\n";
    exit(0);
}

fwrite(STDERR, $usage);
exit(2);
