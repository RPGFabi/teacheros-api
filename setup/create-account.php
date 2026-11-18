<?php

declare(strict_types=1);

// Legt ein Konto (accounts) fuer eine bestehende Auth-Identitaet an, z. B. fuer die lokale Entwicklung:
//   php setup/create-account.php --public-user-id=<sub des JWT> [--status=active|invited|inactive]
// Die Person selbst (und die Verknuepfung zum Auth-Nutzer) folgt in spaeteren Tickets (Einladung/Provisionierung).

use Hoelzle\Core\Application;
use Hoelzle\Core\Config\Config;
use TeacherOS\Shared\Security\AccountRepository;
use TeacherOS\Shared\Security\AccountStatus;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Application::initialize(dirname(__DIR__));
Config::load();

$opts = getopt('', ['public-user-id:', 'status::']);
$id = $opts['public-user-id'] ?? null;
$status = $opts['status'] ?? 'active';
if (!is_string($id) || !preg_match('/^[0-9a-f-]{36}$/i', $id) || AccountStatus::tryFrom((string) $status) === null) {
    fwrite(STDERR, "Aufruf: php setup/create-account.php --public-user-id=<uuid> [--status=active|invited|inactive]\n");
    exit(2);
}

(new AccountRepository())->create($id, AccountStatus::from($status));
echo "Konto $id angelegt (Status $status)\n";
