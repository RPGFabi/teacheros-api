<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Extension;

/**
 * Gemeinsame Grundlage der Erweiterungspunkte (Source of Truth 4.7.1: einheitliche Registries). Ein Modul, das etwas
 * an einem Fachobjekt zu pruefen hat (Schuljahr, Klasse, ...), bekommt eine eigene Registry mit eigenem, typisiertem
 * `register()`; spaetere Module melden dort ihre Pruefung an, ohne den Ablauf des Fachobjekts zu aendern. Eine Registry
 * ist eine gewoehnliche Instanz: der Dienst bekommt sie per Konstruktor, Tests bauen sich eine eigene. Gefragt wird in der
 * Reihenfolge der Anmeldung. Die Instanzen einer Anwendung verwaltet {@see ExtensionPoints}.
 */
abstract class GuardRegistry
{
    /** @var list<object> */
    private array $guards = [];

    protected function add(object $guard): void
    {
        $this->guards[] = $guard;
    }

    /** @return list<object> in der Reihenfolge der Anmeldung */
    protected function guards(): array
    {
        return $this->guards;
    }
}
