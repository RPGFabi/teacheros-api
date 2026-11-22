<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Modules\Classes\Classes;
use TeacherOS\Modules\Classes\SchoolClass;
use TeacherOS\Modules\SchoolYears\SchoolYear;
use TeacherOS\Modules\SchoolYears\SchoolYears;
use TeacherOS\Modules\Subjects\Subject;
use TeacherOS\Modules\Subjects\Subjects;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Database\MysqlError;
use TeacherOS\Shared\Database\Transactions;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Uuid;

/**
 * Fachgruppen ansehen und anlegen (Ticket #64), bearbeiten, archivieren, wiederherstellen und loeschen (Ticket #65,
 * GLOSSARY.md: Fachgruppe, Archivierung). Eine Fachgruppe gehoert unveraenderlich zu einem
 * Schuljahr und einem Fach und hat mindestens eine Klasse desselben Schuljahres. Schuljahre, Faecher und Klassen kommen aus
 * den Diensten {@see SchoolYears}, {@see Subjects} und {@see Classes}, nicht aus deren Tabellen: neu verwendet werden nur
 * waehlbare Faecher ({@see Subjects::selectable()}) und waehlbare, also nicht archivierte Klassen
 * ({@see Classes::selectable()}). Was sich spaeter an Faechern und Klassen aendert (Archivieren), laesst bestehende
 * Fachgruppen unberuehrt. Archivierte Fachgruppen bleiben lesbar und ihre Bezeichnung belegt, sind aber schreibgeschuetzt und
 * nicht waehlbar ({@see self::selectable()}). Geloescht wird nur, was nirgends verknuepft ist; die Verknuepfungen melden
 * spaetere Module ueber {@see TeachingGroupLinkGuards} an. Gegen das Entfernen einer Klasse aus der Fachgruppe koennen sie
 * ueber {@see TeachingGroupClassRemovalGuards} Einwand erheben.
 */
final class TeachingGroups
{
    public function __construct(
        private readonly TeachingGroupRepository $repository = new TeachingGroupRepository(),
        private readonly SchoolYears $schoolYears = new SchoolYears(),
        private readonly Subjects $subjects = new Subjects(),
        private readonly Classes $classes = new Classes(),
        private readonly AuditLog $audit = new AuditLog(),
        private readonly TeachingGroupLinkGuards $linkGuards = new TeachingGroupLinkGuards(),
        private readonly TeachingGroupClassRemovalGuards $classRemovalGuards = new TeachingGroupClassRemovalGuards(),
    ) {
    }

    /**
     * Alle Schuljahre mit Status fuer die Auswahl, nach Beginn. Wer Fachgruppen sieht, braucht dafuer kein `school_years.view`.
     *
     * @return list<SchoolYear>
     */
    public function schoolYears(): array
    {
        return $this->schoolYears->list();
    }

    /**
     * Die fuer eine neue Fachgruppe waehlbaren Faecher. Wer Fachgruppen sieht, braucht dafuer kein `subjects.view`.
     *
     * @return list<Subject>
     */
    public function selectableSubjects(): array
    {
        return $this->subjects->selectable();
    }

    /**
     * Die fuer eine neue Fachgruppe waehlbaren Klassen eines Schuljahres (ohne archivierte). Wer Fachgruppen sieht, braucht
     * dafuer kein `classes.view`.
     *
     * @return list<SchoolClass>
     * @throws CodedException `school_year_not_found` (404)
     */
    public function selectableClasses(string $schoolYearId): array
    {
        return $this->classes->selectable($schoolYearId);
    }

    /**
     * Die Fachgruppen eines Schuljahres, nach Fachkuerzel (natuerlich, ohne Gross-/Kleinschreibung), dann natuerlich nach
     * Bezeichnung. Archivierte Fachgruppen nur mit $includeArchived.
     *
     * @return list<TeachingGroup>
     * @throws CodedException `school_year_not_found` (404)
     */
    public function listIn(string $schoolYearId, bool $includeArchived = false): array
    {
        $year = $this->schoolYears->get($schoolYearId);
        $groups = $this->present($this->repository->inSchoolYear($schoolYearId, $includeArchived), $year, $this->repository->classIdsInSchoolYear($schoolYearId));
        usort($groups, static fn (TeachingGroup $a, TeachingGroup $b): int => (strnatcasecmp($a->subject->code, $b->subject->code) ?: strcmp($a->subject->code, $b->subject->code))
            ?: (strnatcasecmp($a->name, $b->name) ?: strcmp($a->name, $b->name)));
        return $groups;
    }

    /**
     * Die fuer neue Zuordnungen waehlbaren Fachgruppen, also ohne archivierte: eines Schuljahres oder aller Schuljahre (dann
     * nach Beginn des Schuljahres, innerhalb davon wie {@see self::listIn()}). Spaetere Module (Lehrkraft-Zuordnung, #67) fragen
     * hier ab. Eine Fachgruppe mit archiviertem Fach oder archivierten Klassen bleibt waehlbar; nur ihre eigene Archivierung
     * nimmt sie aus der Auswahl.
     *
     * @return list<TeachingGroup>
     * @throws CodedException `school_year_not_found` (404)
     */
    public function selectable(?string $schoolYearId = null): array
    {
        if ($schoolYearId !== null) {
            return $this->listIn($schoolYearId);
        }
        $groups = [];
        foreach ($this->schoolYears->list() as $year) {
            array_push($groups, ...$this->listIn($year->id));
        }
        return $groups;
    }

    /** Eine Fachgruppe nach Kennung, oder null, wenn es keine mit dieser Kennung gibt. */
    public function find(string $id): ?TeachingGroup
    {
        $row = $this->repository->find($id);
        if ($row === null) {
            return null;
        }
        $year = $this->schoolYears->get($row['school_year_id']);
        return $this->present([$row], $year, [$id => $this->repository->classIdsOf($id)])[0];
    }

    /**
     * Wie {@see self::find()}, aber eine fehlende Fachgruppe ist `teaching_group_not_found`.
     *
     * @throws CodedException `teaching_group_not_found` (404)
     */
    public function get(string $id): TeachingGroup
    {
        return $this->find($id) ?? throw new CodedException('teaching_group_not_found', 404, 'Die Fachgruppe gibt es nicht.');
    }

    /**
     * Legt eine Fachgruppe an: Schuljahr (404), waehlbares Fach und waehlbare Klassen desselben Schuljahres (409). Fach und
     * Klassen werden in der Transaktion unter gemeinsamer Sperre geprueft ({@see Subjects::lockSelectable()},
     * {@see Classes::lockSelectable()}), damit ein gleichzeitiges Archivieren keine Fachgruppe mit archiviertem Fach oder
     * archivierter Klasse entstehen laesst. Die Eindeutigkeit der Bezeichnung im Schuljahr sichert der eindeutige Schluessel der
     * Tabelle (auch bei gleichzeitigen Anfragen); Anlegen, Klassen und Audit-Eintrag `teaching_group.created` laufen in
     * derselben Transaktion. Verschwindet das Schuljahr zwischen Pruefung und Schreiben, meldet der Fremdschluessel
     * (Fehler 1452) es als nicht gefunden.
     *
     * @throws CodedException `school_year_not_found` (404), `subject_not_selectable` (409), `class_not_selectable` (409), `teaching_group_name_taken` (409)
     */
    public function create(Actor $actor, TeachingGroupInput $input): TeachingGroup
    {
        $year = $this->schoolYears->get($input->schoolYearId);
        $id = Uuid::v4();
        $subject = $classes = null;
        $this->inTransaction(function () use ($id, $input, $actor, $year, &$subject, &$classes): void {
            $subject = $this->subjects->lockSelectable($input->subjectId) ?? throw self::subjectNotSelectable();
            $classes = $this->classes->lockSelectable($year->ref(), $input->attributes->classIds);
            if (count($classes) !== count($input->attributes->classIds)) {
                throw self::classNotSelectable();
            }
            $this->repository->insert($id, $input);
            $this->audit->record($actor, AuditLog::TEACHING_GROUP_CREATED, 'teaching_group', $id, ['name' => $input->attributes->name]);
        }, SchoolYears::notFound());
        return new TeachingGroup($id, $input->attributes->name, $year->ref(), $subject, $classes);
    }

    /**
     * Aendert Bezeichnung und Klassen nach denselben Regeln wie beim Anlegen ({@see TeachingGroupAttributes}); $classIds ist die
     * gesamte neue Menge der Klassen. Die Eingabe kommt roh und wird erst geprueft, wenn die Fachgruppe gesperrt und als
     * aenderbar erkannt ist: unbekannt (404) und archiviert (409) gehen der Eingabepruefung (400) vor. Schuljahr und Fach sind
     * nicht aenderbar: abweichende $claimedSchoolYearId und $claimedSubjectId werden abgelehnt (400). Neu hinzukommen duerfen
     * nur waehlbare Klassen des Schuljahres der Fachgruppe; eine nach dem Verknuepfen archivierte Klasse bleibt verknuepft,
     * solange sie in $classIds steht. Jede entfallende Klasse fragt {@see TeachingGroupClassRemovalGuards}. Aenderung, Klassen
     * und Audit-Eintrag `teaching_group.updated` laufen in einer Transaktion.
     *
     * @throws CodedException `teaching_group_not_found` (404), `teaching_group_archived` (409), `invalid_request` (400), `class_not_selectable` (409), `teaching_group_name_taken` (409) oder der Einwand eines {@see TeachingGroupClassRemovalGuard} (409)
     */
    public function update(Actor $actor, string $id, mixed $name, mixed $classIds, mixed $claimedSchoolYearId = null, mixed $claimedSubjectId = null): TeachingGroup
    {
        $this->inTransaction(function () use ($actor, $id, $name, $classIds, $claimedSchoolYearId, $claimedSubjectId): void {
            $before = $this->lockedGroup($id);
            if ($before['archived']) {
                throw new CodedException('teaching_group_archived', 409, 'Die Fachgruppe ist archiviert und kann nicht geändert werden. Bitte zuerst wiederherstellen.');
            }
            if ($claimedSchoolYearId !== null && $claimedSchoolYearId !== $before['school_year_id']) {
                throw new CodedException('invalid_request', 400, 'Das Schuljahr einer Fachgruppe lässt sich nicht ändern.');
            }
            if ($claimedSubjectId !== null && $claimedSubjectId !== $before['subject_id']) {
                throw new CodedException('invalid_request', 400, 'Das Fach einer Fachgruppe lässt sich nicht ändern.');
            }
            $attributes = TeachingGroupAttributes::fromInput($name, $classIds);
            $current = $this->repository->classIdsOf($id, lock: true);
            $added = array_values(array_diff($attributes->classIds, $current));
            $removed = array_values(array_diff($current, $attributes->classIds));
            if ($added !== [] && count($this->classes->lockSelectableIds($before['school_year_id'], $added)) !== count($added)) {
                throw self::classNotSelectable();
            }
            foreach ($removed as $classId) {
                $this->classRemovalGuards->assertRemovable($id, $classId);
            }
            $this->repository->update($id, $attributes, $added, $removed);
            $this->audit->record($actor, AuditLog::TEACHING_GROUP_UPDATED, 'teaching_group', $id, ['old_name' => $before['name'], 'name' => $attributes->name, 'classes_added' => count($added), 'classes_removed' => count($removed)]);
        }, self::classNotSelectable());
        return $this->get($id);
    }

    /**
     * Archiviert eine Fachgruppe: sie bleibt lesbar, ihre Bezeichnung belegt und ihre Klassen verknuepft, aber sie ist
     * schreibgeschuetzt und nicht waehlbar. Geht mit und ohne Verknuepfungen.
     *
     * @throws CodedException `teaching_group_not_found` (404), `teaching_group_already_archived` (409)
     */
    public function archive(Actor $actor, string $id): TeachingGroup
    {
        return $this->setArchived($actor, $id, true);
    }

    /**
     * Stellt eine archivierte Fachgruppe wieder her. Ihre Bezeichnung war die ganze Zeit belegt, eine erneute
     * Eindeutigkeitspruefung ist daher nicht noetig.
     *
     * @throws CodedException `teaching_group_not_found` (404), `teaching_group_not_archived` (409)
     */
    public function restore(Actor $actor, string $id): TeachingGroup
    {
        return $this->setArchived($actor, $id, false);
    }

    /**
     * Loescht eine Fachgruppe endgueltig samt ihren Klassen-Verknuepfungen (die Klassen bleiben), solange nichts an ihr haengt
     * (auch eine archivierte). Sonst wird abgelehnt und auf das Archivieren verwiesen.
     *
     * @throws CodedException `teaching_group_not_found` (404), `teaching_group_linked` (409)
     */
    public function delete(Actor $actor, string $id): void
    {
        $this->inTransaction(function () use ($actor, $id): void {
            $group = $this->lockedGroup($id);
            $this->linkGuards->assertDeletable($id);
            $this->repository->delete($id);
            $this->audit->record($actor, AuditLog::TEACHING_GROUP_DELETED, 'teaching_group', $id, ['name' => $group['name']]);
        });
    }

    private function setArchived(Actor $actor, string $id, bool $archived): TeachingGroup
    {
        $this->inTransaction(function () use ($actor, $id, $archived): void {
            $group = $this->lockedGroup($id);
            if ($group['archived'] === $archived) {
                throw $archived
                    ? new CodedException('teaching_group_already_archived', 409, 'Die Fachgruppe ist bereits archiviert.')
                    : new CodedException('teaching_group_not_archived', 409, 'Die Fachgruppe ist nicht archiviert.');
            }
            $this->repository->setArchived($id, $archived);
            $this->audit->record($actor, $archived ? AuditLog::TEACHING_GROUP_ARCHIVED : AuditLog::TEACHING_GROUP_RESTORED, 'teaching_group', $id, ['name' => $group['name']]);
        });
        return $this->get($id);
    }

    /**
     * Die Fachgruppe, fuer die Dauer der laufenden Transaktion gesperrt, damit gleichzeitiges Bearbeiten, Archivieren und
     * Loeschen sich nicht ueberholt.
     *
     * @return array{id:string,school_year_id:string,subject_id:string,name:string,archived:bool}
     * @throws CodedException `teaching_group_not_found` (404)
     */
    private function lockedGroup(string $id): array
    {
        return $this->repository->find($id, lock: true) ?? throw new CodedException('teaching_group_not_found', 404, 'Die Fachgruppe gibt es nicht.');
    }

    /**
     * Fuehrt die Aenderung samt Audit in einer Transaktion aus. Datenbankfehler der Schluessel werden ueber die Fehlernummer zu
     * fachlichen: doppelte Bezeichnung ist `teaching_group_name_taken` (409); ein Verweis auf eine Zeile, die es nicht (mehr)
     * gibt (1452), ist $missingReference. Fach und Klassen sind in der Transaktion gesperrt und geprueft, ein Verweis kann
     * daher nur noch am (ungesperrten) Schuljahr scheitern.
     */
    private function inTransaction(\Closure $change, ?CodedException $missingReference = null): void
    {
        try {
            Transactions::run($change);
        } catch (\PDOException $e) {
            throw match (MysqlError::of($e)) {
                MysqlError::DUPLICATE_ENTRY => new CodedException('teaching_group_name_taken', 409, 'Im Schuljahr gibt es bereits eine Fachgruppe mit dieser Bezeichnung.'),
                MysqlError::NO_REFERENCED_ROW => $missingReference ?? $e,
                default => $e,
            };
        }
    }

    private static function subjectNotSelectable(): CodedException
    {
        return new CodedException('subject_not_selectable', 409, 'Das Fach gibt es nicht oder es ist nicht wählbar.');
    }

    private static function classNotSelectable(): CodedException
    {
        return new CodedException('class_not_selectable', 409, 'Mindestens eine Klasse gibt es nicht, ist archiviert oder gehört nicht zum Schuljahr der Fachgruppe.');
    }

    /**
     * @param list<array{id:string,school_year_id:string,subject_id:string,name:string,archived:bool}> $rows Fachgruppen eines Schuljahres
     * @param array<string,list<string>> $classIdsByGroup
     * @return list<TeachingGroup>
     */
    private function present(array $rows, SchoolYear $year, array $classIdsByGroup): array
    {
        if ($rows === []) {
            return [];
        }
        $subjects = [];
        foreach ($this->subjects->list(includeArchived: true) as $subject) {
            $subjects[$subject->id] = $subject;
        }
        $classes = $this->classes->listIn($year->id, includeArchived: true);
        return array_map(function (array $row) use ($year, $subjects, $classes, $classIdsByGroup): TeachingGroup {
            $ids = $classIdsByGroup[$row['id']] ?? [];
            $own = array_values(array_filter($classes, static fn (SchoolClass $class): bool => in_array($class->id, $ids, true)));
            return new TeachingGroup($row['id'], $row['name'], $year->ref(), $subjects[$row['subject_id']] ?? throw new \LogicException('Das Fach der Fachgruppe fehlt.'), $own, $row['archived']);
        }, $rows);
    }
}
