<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use TeacherOS\Shared\Extension\LinkGuard;

/**
 * Erweiterungspunkt der Verknuepfungspruefung eines Faches (Ticket #63, GLOSSARY.md: Archivierung). Ein spaeteres Modul,
 * das Faecher verknuepft (Fachgruppen, Zuordnungen, ...), implementiert dies und meldet es in seiner Modul-Registrierung bei
 * {@see SubjectLinkGuards} an (`$points->get(SubjectLinkGuards::class)->register(...)`); der Ablauf in {@see Subjects}
 * bleibt unveraendert. Ein verknuepftes Fach wird nicht geloescht, sondern archiviert.
 */
interface SubjectLinkGuard extends LinkGuard
{
}
