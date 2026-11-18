<?php

declare(strict_types=1);

// Test-Hilfsskript: ruft AccessAdministration::reactivateAccount() fuer ein beliebiges handelndes Konto auf (Ticket #43).
//   php tests/Support/reactivate-as.php <actor_public_user_id> <target_public_user_id>
// Noetig fuer Faelle, die ueber HTTP nicht zu haben sind (ein deaktiviertes Konto kommt nicht durch die Anmeldung,
// kann sich also nicht "selbst" reaktivieren). Ausgabe `ok` (Exit 0) oder der Fehlercode (Exit 3).

use Hoelzle\Core\Application;
use Hoelzle\Core\Config\Config;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AccessAdministration;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

Application::initialize(dirname(__DIR__, 2));
Config::load();

try {
    (new AccessAdministration())->reactivateAccount(Actor::account($argv[1]), $argv[2]);
    echo "ok\n";
} catch (CodedException $e) {
    echo $e->errorCode . "\n";
    exit(3);
}
