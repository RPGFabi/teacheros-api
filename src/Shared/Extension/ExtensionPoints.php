<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Extension;

/**
 * Haelt je Registry-Klasse genau eine Instanz fuer die laufende Anwendung. Jedes Modul holt hier die Registries, an denen es
 * sich anmeldet (`get(SchoolYearGuards::class)->register(...)`), und reicht dieselben Instanzen an seine Dienste weiter;
 * der Kern muss keine Registry kennen. Kein statischer Zustand: jede Anwendung (und jeder Test) baut ihre eigene Sammlung.
 */
final class ExtensionPoints
{
    /** @var array<class-string<GuardRegistry>,GuardRegistry> */
    private array $registries = [];

    /**
     * @template T of GuardRegistry
     * @param class-string<T> $registry
     * @return T
     */
    public function get(string $registry): GuardRegistry
    {
        return $this->registries[$registry] ??= new $registry();
    }
}
