<?php

declare(strict_types=1);

use Hoelzle\Core\Database\DB;

// Markierung "Abgleich offen" (ADR 0002 B2, Nachtrag #12, Ticket #42): 1, solange die lokale Deaktivierung dem
// Anmeldedienst noch nicht gemeldet ist (`disable-login`). Die Deaktivierung setzt sie in derselben Transaktion; der
// erfolgreiche Aufruf (oder spaeter die Reaktivierung) leert sie. Gefahrlos wiederholbar (IF NOT EXISTS).
DB::getInstance()->exec('ALTER TABLE `accounts` ADD COLUMN IF NOT EXISTS `login_sync_pending` tinyint(1) NOT NULL DEFAULT 0 AFTER `inactive_reason`');
