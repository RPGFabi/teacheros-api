<?php

declare(strict_types=1);

// Laedt den ersten Schuladmin einer Schulinstanz ein (Spec #10, Ticket #25):
//   php bin/invite-first-admin.php --email=admin@schule.example [--login-name=name] [--allow-additional-admin]
// Exit-Codes: 0 ok, 2 Aufruf falsch, 3 abgelehnt/Konflikt (z. B. aktiver Schuladmin vorhanden), 4 Anmeldedienst nicht verfuegbar.

use Hoelzle\Core\Application;
use Hoelzle\Core\Config\Config;
use TeacherOS\Modules\Accounts\AccountInvitations;
use TeacherOS\Shared\AuthService\AuthServiceUnavailableException;
use TeacherOS\Shared\Exceptions\CodedException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Application::initialize(dirname(__DIR__));
Config::load();

$opts = getopt('', ['email::', 'login-name::', 'allow-additional-admin']);
$email = isset($opts['email']) && is_string($opts['email']) && trim($opts['email']) !== '' ? trim($opts['email']) : null;
$loginName = isset($opts['login-name']) && is_string($opts['login-name']) && trim($opts['login-name']) !== '' ? trim($opts['login-name']) : null;
if (($email === null && $loginName === null) || ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
    fwrite(STDERR, "Aufruf: php bin/invite-first-admin.php --email=<adresse> [--login-name=<name>] [--allow-additional-admin]\n");
    exit(2);
}

try {
    $result = (new AccountInvitations())->inviteFirstSchuladmin($email, $loginName, isset($opts['allow-additional-admin']));
} catch (AuthServiceUnavailableException $e) {
    fwrite(STDERR, "Fehler (auth_unavailable): {$e->getMessage()}\nNichts wurde angelegt; der Aufruf kann gefahrlos wiederholt werden.\n");
    exit(4);
} catch (CodedException $e) {
    fwrite(STDERR, "Abgelehnt ({$e->errorCode}): {$e->getMessage()}\n");
    exit(3);
}

echo ($result->reinvited ? 'Erneut eingeladen' : 'Eingeladen') . ": {$result->loginName} (Konto {$result->publicUserId})\n";
if ($result->inviteCode !== null) {
    echo "Einladungscode (wird nur jetzt angezeigt): {$result->inviteCode}\n";
}