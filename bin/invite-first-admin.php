<?php

declare(strict_types=1);

// Laedt den ersten Schuladmin einer Schulinstanz ein (Spec #10, Ticket #25):
//   php bin/invite-first-admin.php --email=admin@schule.example [--login-name=name] [--allow-additional-admin]
// Exit-Codes: 0 ok, 2 Aufruf falsch, 3 fachlich abgelehnt (auch vom Anmeldedienst, z. B. aktiver Schuladmin vorhanden,
// Anmeldename vergeben), 4 Anmeldedienst nicht verfuegbar (Ausfall, Timeout, unlesbare Antwort).

use Hoelzle\Core\Application;
use Hoelzle\Core\Config\Config;
use TeacherOS\Modules\Accounts\AccountInvitations;
use TeacherOS\Modules\Accounts\InvitationIdentifiers;
use TeacherOS\Shared\AuthService\AuthServiceUnavailableException;
use TeacherOS\Shared\Exceptions\CodedException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Application::initialize(dirname(__DIR__));
Config::load();

$opts = getopt('', ['email::', 'login-name::', 'allow-additional-admin']);
try {
    $ids = InvitationIdentifiers::from($opts['email'] ?? null, $opts['login-name'] ?? null);
} catch (CodedException) {
    fwrite(STDERR, "Aufruf: php bin/invite-first-admin.php [--email=<adresse>] [--login-name=<name>] (mindestens eins) [--allow-additional-admin]\n");
    exit(2);
}

try {
    $result = (new AccountInvitations())->inviteFirstSchuladmin($ids->email, $ids->loginName, isset($opts['allow-additional-admin']));
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
if ($result->inviteExpiresAt !== null) {
    echo "Gueltig bis: {$result->inviteExpiresAt}\n";
}
