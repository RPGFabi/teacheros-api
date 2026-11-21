<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

/**
 * Erweiterungspunkt der Verknuepfungspruefung einer Fachgruppe (Ticket #65, GLOSSARY.md: Archivierung). Ein spaeteres Modul,
 * das Fachgruppen verknuepft (Lehrkraft-Zuordnung #67, Unterrichtsplanung, ...), implementiert dies und meldet es in seiner
 * Modul-Registrierung bei {@see TeachingGroupLinkGuards} an (`$points->get(TeachingGroupLinkGuards::class)->register(...)`);
 * der Ablauf in {@see TeachingGroups} bleibt unveraendert. Eine verknuepfte Fachgruppe wird nicht geloescht, sondern
 * archiviert.
 */
interface TeachingGroupLinkGuard
{
    /** Kurze Bezeichnung der Verknuepfung fuer die Fehlermeldung, z. B. "Lehrkraft-Zuordnung". */
    public function label(): string;

    /** Haengt an der Fachgruppe (Kennung) bereits etwas dieses Moduls? */
    public function isLinked(string $teachingGroupId): bool;
}
