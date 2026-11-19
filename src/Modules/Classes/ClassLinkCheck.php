<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

/**
 * Erweiterungspunkt der Verknuepfungspruefung einer Klasse (Ticket #57, GLOSSARY.md: Archivierung). Ein spaeteres Modul,
 * das Klassen verknuepft (Stundenplan, Schueler, ...), implementiert dies und meldet es bei {@see ClassLinkRegistry} an;
 * der Ablauf in {@see Classes} bleibt unveraendert. Eine verknuepfte Klasse wird nicht geloescht, sondern archiviert.
 */
interface ClassLinkCheck
{
    /** Kurze Bezeichnung der Verknuepfung fuer die Fehlermeldung, z. B. "Stundenplan". */
    public function label(): string;

    /** Haengt an der Klasse (Kennung) bereits etwas dieses Moduls? */
    public function isLinked(string $classId): bool;
}
