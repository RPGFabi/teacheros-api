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
 * Fachgruppen ansehen und anlegen (Ticket #64, GLOSSARY.md: Fachgruppe). Eine Fachgruppe gehoert unveraenderlich zu einem
 * Schuljahr und einem Fach und hat mindestens eine Klasse desselben Schuljahres. Schuljahre, Faecher und Klassen kommen aus
 * den Diensten {@see SchoolYears}, {@see Subjects} und {@see Classes}, nicht aus deren Tabellen: neu verwendet werden nur
 * waehlbare Faecher ({@see Subjects::selectable()}) und waehlbare, also nicht archivierte Klassen
 * ({@see Classes::selectable()}). Was sich spaeter an Faechern und Klassen aendert (Archivieren), laesst bestehende
 * Fachgruppen unberuehrt.
 */
final class TeachingGroups
{
    public function __construct(
        private readonly TeachingGroupRepository $repository = new TeachingGroupRepository(),
        private readonly SchoolYears $schoolYears = new SchoolYears(),
        private readonly Subjects $subjects = new Subjects(),
        private readonly Classes $classes = new Classes(),
        private readonly AuditLog $audit = new AuditLog(),
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
     * Bezeichnung.
     *
     * @return list<TeachingGroup>
     * @throws CodedException `school_year_not_found` (404)
     */
    public function listIn(string $schoolYearId): array
    {
        $year = $this->schoolYears->get($schoolYearId);
        $groups = $this->present($this->repository->inSchoolYear($schoolYearId), $year, $this->repository->classIdsInSchoolYear($schoolYearId));
        usort($groups, static fn (TeachingGroup $a, TeachingGroup $b): int => (strnatcasecmp($a->subject->code, $b->subject->code) ?: strcmp($a->subject->code, $b->subject->code))
            ?: (strnatcasecmp($a->name, $b->name) ?: strcmp($a->name, $b->name)));
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
     * Legt eine Fachgruppe an: Schuljahr (404), waehlbares Fach und waehlbare Klassen desselben Schuljahres (409) werden
     * vorher ueber die Dienste geprueft. Die Eindeutigkeit der Bezeichnung im Schuljahr sichert der eindeutige Schluessel der
     * Tabelle (auch bei gleichzeitigen Anfragen); Anlegen, Klassen und Audit-Eintrag `teaching_group.created` laufen in einer
     * Transaktion. Verschwindet Schuljahr, Fach oder eine Klasse zwischen Pruefung und Schreiben, meldet der Fremdschluessel
     * dasselbe wie die Pruefung.
     *
     * @throws CodedException `school_year_not_found` (404), `subject_not_selectable` (409), `class_not_selectable` (409), `teaching_group_name_taken` (409)
     */
    public function create(Actor $actor, TeachingGroupInput $input): TeachingGroup
    {
        $year = $this->schoolYears->get($input->schoolYearId);
        $subject = $this->selectableSubject($input->subjectId);
        $classes = $this->selectableClassesOf($input);
        $id = Uuid::v4();
        try {
            Transactions::run(function () use ($id, $input, $actor): void {
                $this->repository->insert($id, $input);
                $this->audit->record($actor, AuditLog::TEACHING_GROUP_CREATED, 'teaching_group', $id, ['name' => $input->name]);
            });
        } catch (\PDOException $e) {
            throw $this->translated($e);
        }
        return new TeachingGroup($id, $input->name, $year->ref(), $subject, $classes);
    }

    /** @throws CodedException `subject_not_selectable` (409) */
    private function selectableSubject(string $subjectId): Subject
    {
        foreach ($this->subjects->selectable() as $subject) {
            if ($subject->id === $subjectId) {
                return $subject;
            }
        }
        throw new CodedException('subject_not_selectable', 409, 'Das Fach gibt es nicht oder es ist nicht wählbar.');
    }

    /**
     * Die Klassen der Eingabe, so wie sie waehlbar sind (nach Klassenstufe und Bezeichnung).
     *
     * @return list<SchoolClass>
     * @throws CodedException `class_not_selectable` (409), wenn eine Klasse fehlt, archiviert ist oder zu einem anderen Schuljahr gehoert
     */
    private function selectableClassesOf(TeachingGroupInput $input): array
    {
        $selected = array_values(array_filter(
            $this->classes->selectable($input->schoolYearId),
            static fn (SchoolClass $class): bool => in_array($class->id, $input->classIds, true),
        ));
        if (count($selected) !== count($input->classIds)) {
            throw new CodedException('class_not_selectable', 409, 'Mindestens eine Klasse gibt es nicht, ist archiviert oder gehört nicht zum Schuljahr der Fachgruppe.');
        }
        return $selected;
    }

    /**
     * Datenbankfehler der Schluessel werden zu fachlichen: doppelte Bezeichnung ist `teaching_group_name_taken` (409); was
     * zwischen Pruefung und Schreiben verschwunden ist, meldet wie die Pruefung (Schuljahr 404, Fach und Klasse 409).
     */
    private function translated(\PDOException $e): \Throwable
    {
        return match (MysqlError::of($e)) {
            MysqlError::DUPLICATE_ENTRY => new CodedException('teaching_group_name_taken', 409, 'Im Schuljahr gibt es bereits eine Fachgruppe mit dieser Bezeichnung.'),
            MysqlError::NO_REFERENCED_ROW => match (true) {
                str_contains($e->getMessage(), 'fk_teaching_groups_school_year') => SchoolYears::notFound(),
                str_contains($e->getMessage(), 'fk_teaching_groups_subject') => new CodedException('subject_not_selectable', 409, 'Das Fach gibt es nicht oder es ist nicht wählbar.'),
                default => new CodedException('class_not_selectable', 409, 'Mindestens eine Klasse gibt es nicht, ist archiviert oder gehört nicht zum Schuljahr der Fachgruppe.'),
            },
            default => $e,
        };
    }

    /**
     * @param list<array{id:string,school_year_id:string,subject_id:string,name:string}> $rows Fachgruppen eines Schuljahres
     * @param array<string,list<string>> $classIdsByGroup
     * @return list<TeachingGroup>
     */
    private function present(array $rows, SchoolYear $year, array $classIdsByGroup): array
    {
        if ($rows === []) {
            return [];
        }
        $subjects = [];
        foreach ($this->subjects->list() as $subject) {
            $subjects[$subject->id] = $subject;
        }
        $classes = $this->classes->listIn($year->id, includeArchived: true);
        return array_map(function (array $row) use ($year, $subjects, $classes, $classIdsByGroup): TeachingGroup {
            $ids = $classIdsByGroup[$row['id']] ?? [];
            $own = array_values(array_filter($classes, static fn (SchoolClass $class): bool => in_array($class->id, $ids, true)));
            return new TeachingGroup($row['id'], $row['name'], $year->ref(), $subjects[$row['subject_id']] ?? throw new \LogicException('Das Fach der Fachgruppe fehlt.'), $own);
        }, $rows);
    }
}
