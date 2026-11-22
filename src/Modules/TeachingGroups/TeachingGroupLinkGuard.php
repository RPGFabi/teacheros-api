<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Shared\Extension\LinkGuard;

/**
 * Erweiterungspunkt der Verknuepfungspruefung einer Fachgruppe (Ticket #65, GLOSSARY.md: Archivierung). Ein spaeteres Modul,
 * das Fachgruppen verknuepft (Lehrkraft-Zuordnung #67, Unterrichtsplanung, ...), implementiert dies und meldet es in seiner
 * Modul-Registrierung bei {@see TeachingGroupLinkGuards} an (`$points->get(TeachingGroupLinkGuards::class)->register(...)`);
 * der Ablauf in {@see TeachingGroups} bleibt unveraendert. Eine verknuepfte Fachgruppe wird nicht geloescht, sondern
 * archiviert.
 */
interface TeachingGroupLinkGuard extends LinkGuard
{
}
