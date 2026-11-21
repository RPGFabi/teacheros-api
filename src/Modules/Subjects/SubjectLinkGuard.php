<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

/**
 * Erweiterungspunkt der Verknuepfungspruefung eines Faches (Ticket #63, GLOSSARY.md: Archivierung). Ein spaeteres Modul,
 * das Faecher verknuepft (Fachgruppen, Zuordnungen, ...), implementiert dies und meldet es in seiner Modul-Registrierung bei
 * {@see SubjectLinkGuards} an (`$points->get(SubjectLinkGuards::class)->register(...)`); der Ablauf in {@see Subjects}
 * bleibt unveraendert. Ein verknuepftes Fach wird nicht geloescht, sondern archiviert.
 */
interface SubjectLinkGuard
{
    /** Kurze Bezeichnung der Verknuepfung fuer die Fehlermeldung, z. B. "Fachgruppe". */
    public function label(): string;

    /** Haengt am Fach (Kennung) bereits etwas dieses Moduls? */
    public function isLinked(string $subjectId): bool;
}
