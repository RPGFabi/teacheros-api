<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

/**
 * Erweiterungspunkt gegen das Entfernen einer Klasse aus einer Fachgruppe (Ticket #65). Ein spaeteres Modul, dem an der
 * Klasse in der Fachgruppe etwas haengt (Mitglieder aus dieser Klasse, #19), implementiert dies und meldet es in seiner
 * Modul-Registrierung bei {@see TeachingGroupClassRemovalGuards} an; der Ablauf in {@see TeachingGroups} bleibt unveraendert.
 * Die Pruefung laeuft in der Transaktion der Aenderung, unter der Sperre der Fachgruppe.
 */
interface TeachingGroupClassRemovalGuard
{
    /** Einwand gegen das Entfernen dieser Klasse aus dieser Fachgruppe, oder null, wenn nichts dagegen spricht. */
    public function objectionToRemoval(string $teachingGroupId, string $classId): ?TeachingGroupClassRemovalObjection;
}
