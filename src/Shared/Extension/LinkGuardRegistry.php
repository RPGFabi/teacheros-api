<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Extension;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Gemeinsame Grundlage der Verknuepfungs-Registries (`ClassLinkGuards`, `SubjectLinkGuards`, `TeachingGroupLinkGuards`): sammelt
 * die {@see LinkGuard}s der Module (Muster {@see GuardRegistry}) und beantwortet, woran ein Objekt haengt und ob es geloescht
 * werden darf. Die Unterklasse legt nur fest, wie das Objekt heisst und welcher Fehlercode gilt, und bietet ein typisiertes
 * `register()` fuer ihre eigene Schnittstelle. Ohne Anmeldung gilt ein Objekt als unverknuepft.
 */
abstract class LinkGuardRegistry extends GuardRegistry
{
    /** Der Fehlercode, wenn ein verknuepftes Objekt geloescht werden soll, z. B. `class_linked`. */
    abstract protected function errorCode(): string;

    /** Das Objekt mit Artikel am Satzanfang, z. B. "Die Klasse". */
    abstract protected function subject(): string;

    /**
     * Bezeichnungen aller Verknuepfungen, die am Objekt haengen; leer, wenn es nirgends verknuepft ist.
     *
     * @return list<string>
     */
    public function linksOf(string $id): array
    {
        $labels = [];
        foreach ($this->guards() as $guard) {
            if ($guard instanceof LinkGuard && $guard->isLinked($id)) {
                $labels[] = $guard->label();
            }
        }
        return $labels;
    }

    /** @throws CodedException 409 mit dem Code der Unterklasse, die Meldung nennt alle Verknuepfungen */
    public function assertDeletable(string $id): void
    {
        $links = $this->linksOf($id);
        if ($links !== []) {
            throw new CodedException($this->errorCode(), 409, $this->subject() . ' ist verknüpft (' . implode(', ', $links) . ') und kann nicht gelöscht werden. Bitte stattdessen archivieren.');
        }
    }
}
