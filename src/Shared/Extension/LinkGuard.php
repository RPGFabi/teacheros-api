<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Extension;

/**
 * Die Pruefung eines Moduls, ob an einem Fachobjekt (Klasse, Fach, Fachgruppe) etwas dieses Moduls haengt (GLOSSARY.md:
 * Archivierung). Jedes Fachobjekt hat seine eigene Schnittstelle, die diese erweitert und bei seiner {@see LinkGuardRegistry}
 * angemeldet wird; ein verknuepftes Objekt wird nicht geloescht, sondern archiviert.
 */
interface LinkGuard
{
    /** Kurze Bezeichnung der Verknuepfung fuer die Fehlermeldung, z. B. "Stundenplan". */
    public function label(): string;

    /** Haengt am Objekt (Kennung) bereits etwas dieses Moduls? */
    public function isLinked(string $id): bool;
}
