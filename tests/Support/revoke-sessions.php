<?php

declare(strict_types=1);

// Test-Hilfsskript (Ticket #48): ruft den wiederverwendbaren Baustein SessionRevocation auf, so wie "Mein Konto" (#50) es
// mit mehreren Versuchen tun wird.
//   php tests/Support/revoke-sessions.php <public_user_id> <versuche> <admin|self>
// Ausgabe: {"completed":bool}.

use Hoelzle\Core\Application;
use Hoelzle\Core\Config\Config;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Security\RevocationInitiator;
use TeacherOS\Shared\Security\SessionRevocation;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

Application::initialize(dirname(__DIR__, 2));
Config::load();

$completed = (new SessionRevocation())->revoke(
    Actor::account($argv[1]),
    $argv[1],
    RevocationInitiator::from($argv[3] === 'self' ? 'self' : 'admin'),
    (int) $argv[2],
);
echo json_encode(['completed' => $completed]) . "\n";
