<?php

declare(strict_types=1);

// Test-Hilfsskript: ruft AccessAdministration::revokeAllRoles() auf (Akteur: CLI).
//   php tests/Support/revoke-all-roles.php <public_user_id>
// Ausgabe `ok` (Exit 0) oder der Fehlercode (Exit 3).

use Hoelzle\Core\Application;
use Hoelzle\Core\Config\Config;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AccessAdministration;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

Application::initialize(dirname(__DIR__, 2));
Config::load();

try {
    (new AccessAdministration())->revokeAllRoles($argv[1], Actor::cli());
    echo "ok\n";
} catch (CodedException $e) {
    echo $e->errorCode . "\n";
    exit(3);
}
