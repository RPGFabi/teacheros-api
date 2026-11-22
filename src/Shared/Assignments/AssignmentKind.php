<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Assignments;

use TeacherOS\Modules\SchoolSettings\SchoolLimits;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Woran eine Art von Zuordnung haengt (Strategie fuer {@see Assignments}, {@see AssignmentRepository},
 * {@see AssignmentSchoolYearGuard} und {@see AssignmentsController}): die Lehrkraft-Zuordnung an einer Fachgruppe oder die
 * Klassenleitung an einer Klasse. Alles, was sich zwischen beiden unterscheidet, steht hier und nur hier: Tabelle und
 * Spalte des Elternobjekts, Objektart und Namen der Audit-Eintraege, JSON-Schluessel, Fehlercodes samt Meldungen, Einwand
 * gegen das Aendern des Schuljahres und die Obergrenze aus den Schuleinstellungen. Die Regeln selbst ({@see AssignmentRules})
 * sind fuer beide gleich. Die Tabellen- und Spaltennamen sind feste Werte der Module, nie Eingabe.
 */
final class AssignmentKind
{
    /**
     * @param string $table Tabelle der Zuordnungen (Spalten `id`, $parentColumn, `account_id`, `valid_from`, `valid_until`)
     * @param string $parentTable Tabelle des Elternobjekts (Spalten `id`, `school_year_id`, `archived_at`)
     * @param string $parentColumn Spalte der Zuordnung, die auf das Elternobjekt verweist; zugleich Schluessel in Query, Body und Ausgabe
     * @param string $objectType Objektart der Audit-Eintraege und Schluessel der einzelnen Zuordnung in der Antwort
     * @param string $collectionKey Schluessel der Liste in der Antwort
     * @param string $parentLabel Wie die Eingabepruefung das Elternobjekt nennt (Meldung `invalid_request`)
     * @param \Closure(SchoolLimits):int $limit Obergrenze gleichzeitiger Zuordnungen je Elternobjekt
     */
    public function __construct(
        public readonly string $table,
        public readonly string $parentTable,
        public readonly string $parentColumn,
        public readonly string $objectType,
        public readonly string $collectionKey,
        public readonly string $parentLabel,
        public readonly string $auditCreated,
        public readonly string $auditEnded,
        public readonly string $auditDeleted,
        public readonly string $parentNotFoundCode,
        public readonly string $parentNotFoundMessage,
        public readonly string $parentArchivedCode,
        public readonly string $parentArchivedMessage,
        public readonly string $notFoundCode,
        public readonly string $notFoundMessage,
        public readonly string $schoolYearCode,
        public readonly string $schoolYearOutsideMessage,
        public readonly string $schoolYearLossMessage,
        private readonly \Closure $limit,
    ) {
    }

    public function maxPerParent(SchoolLimits $limits): int
    {
        return ($this->limit)($limits);
    }

    public function parentNotFound(): CodedException
    {
        return new CodedException($this->parentNotFoundCode, 404, $this->parentNotFoundMessage);
    }

    public function parentArchived(): CodedException
    {
        return new CodedException($this->parentArchivedCode, 409, $this->parentArchivedMessage);
    }

    public function notFound(): CodedException
    {
        return new CodedException($this->notFoundCode, 404, $this->notFoundMessage);
    }
}
