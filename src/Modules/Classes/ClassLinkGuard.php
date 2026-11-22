<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

use TeacherOS\Shared\Extension\LinkGuard;

/**
 * Erweiterungspunkt der Verknuepfungspruefung einer Klasse (Ticket #57, GLOSSARY.md: Archivierung). Ein spaeteres Modul,
 * das Klassen verknuepft (Stundenplan, Schueler, ...), implementiert dies und meldet es in seiner Modul-Registrierung bei
 * {@see ClassLinkGuards} an (`$points->get(ClassLinkGuards::class)->register(...)`); der Ablauf in {@see Classes} bleibt
 * unveraendert. Eine verknuepfte Klasse wird nicht geloescht, sondern archiviert.
 */
interface ClassLinkGuard extends LinkGuard
{
}
