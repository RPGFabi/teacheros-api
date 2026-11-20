<?php

declare(strict_types=1);

namespace TeacherOS\Bootstrap;

use TeacherOS\Shared\Extension\ExtensionPoints;

/**
 * Wie sich ein Modul an der Anwendung anmeldet: seine Routen am Router und seine Pruefungen an den Erweiterungspunkten
 * anderer Module, beides an dieser einen Stelle. {@see AppBootstrap} fuehrt nur die Liste der Module und kennt weder die
 * Routen noch die Pruefungen im Einzelnen.
 */
interface ModuleRegistration
{
    public function register(ApiRouter $router, ExtensionPoints $points): void;
}
