<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Fachliche Ablehnung beim Abgleich (Review #12, Q26): lehnt der Anmeldedienst `disable-login` mit einem Code ab
// (z. B. `user_not_found`), bleibt "Abgleich offen" (`login_sync_pending`) bestehen und der Code steht hier. NULL bei
// Nichterreichbarkeit, nach erfolgreichem Abgleich und nach der Reaktivierung. Gefahrlos wiederholbar (IF NOT EXISTS).
DB::getInstance()->exec('ALTER TABLE `accounts` ADD COLUMN IF NOT EXISTS `login_sync_error` varchar(64) NULL DEFAULT NULL AFTER `login_sync_pending`');
