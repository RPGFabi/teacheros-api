<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Database\MysqlError;
use TeacherOS\Shared\Database\Transactions;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Text\Text;
use TeacherOS\Shared\Uuid;

/**
 * Faecher ansehen und anlegen (Ticket #62), bearbeiten, archivieren, wiederherstellen und loeschen (Ticket #63,
 * GLOSSARY.md: Fach, Archivierung). Ein Fach gilt fuer die ganze Schulinstanz, unabhaengig vom Schuljahr; sein Kuerzel ist
 * eindeutig (ohne Gross-/Kleinschreibung) und danach unveraenderlich, nur die Bezeichnung laesst sich aendern. Archivierte
 * Faecher bleiben lesbar und ihr Kuerzel belegt, sind aber schreibgeschuetzt und nicht waehlbar. Geloescht wird nur, was
 * nirgends verknuepft ist; die Verknuepfungen melden spaetere Module (Fachgruppen) ueber {@see SubjectLinkGuards} an.
 * Spaetere Module fragen die waehlbaren Faecher bei {@see self::selectable()} ab.
 */
final class Subjects
{
    public function __construct(
        private readonly SubjectRepository $repository = new SubjectRepository(),
        private readonly AuditLog $audit = new AuditLog(),
        private readonly SubjectLinkGuards $linkGuards = new SubjectLinkGuards(),
    ) {
    }

    /**
     * Die Faecher, natuerlich nach Kuerzel sortiert (D1, D2, D10; Gross-/Kleinschreibung egal). Archivierte nur mit
     * $includeArchived.
     *
     * @return list<Subject>
     */
    public function list(bool $includeArchived = false): array
    {
        $subjects = $this->repository->all($includeArchived);
        usort($subjects, static fn (Subject $a, Subject $b): int => strnatcasecmp($a->code, $b->code) ?: strcmp($a->code, $b->code));
        return $subjects;
    }

    /**
     * Die fuer neue Zuordnungen waehlbaren Faecher (Fachgruppen waehlen ihr Fach hier), also ohne archivierte, sortiert wie
     * {@see self::list()}.
     *
     * @return list<Subject>
     */
    public function selectable(): array
    {
        return $this->list();
    }

    /**
     * Das waehlbare Fach, fuer die Dauer der laufenden Transaktion gesperrt: wer darauf hin etwas speichert (Fachgruppe anlegen),
     * muss in einer Transaktion fragen, damit ein gleichzeitiges Archivieren nicht zwischen Pruefung und Schreiben kommt.
     * Archiviert oder unbekannt ist null.
     */
    public function lockSelectable(string $id): ?Subject
    {
        return $this->repository->findSelectableShared($id);
    }

    /**
     * Legt ein Fach an. Die Eindeutigkeit des Kuerzels sichert der eindeutige Schluessel der Tabelle (auch bei gleichzeitigen
     * Anfragen); Anlegen und Audit-Eintrag `subject.created` laufen in einer Transaktion.
     *
     * @throws CodedException `subject_code_taken` (409)
     */
    public function create(Actor $actor, SubjectInput $input): Subject
    {
        $id = Uuid::v4();
        try {
            Transactions::run(function () use ($id, $input, $actor): void {
                $this->repository->insert($id, $input);
                $this->audit->record($actor, AuditLog::SUBJECT_CREATED, 'subject', $id, ['code' => $input->code, 'name' => $input->name]);
            });
        } catch (\PDOException $e) {
            throw MysqlError::of($e) === MysqlError::DUPLICATE_ENTRY
                ? new CodedException('subject_code_taken', 409, 'Es gibt bereits ein Fach mit diesem Kürzel.')
                : $e;
        }
        return new Subject($id, $input->code, $input->name);
    }

    /**
     * Aendert die Bezeichnung nach denselben Regeln wie beim Anlegen ({@see SubjectInput::cleanName()}). Die Eingabe kommt
     * roh und wird erst geprueft, wenn das Fach gesperrt und als aenderbar erkannt ist: unbekannt (404) und archiviert (409)
     * gehen der Eingabepruefung (400) vor. Das Kuerzel ist nicht aenderbar: ein abweichendes `$claimedCode` wird abgelehnt
     * (dasselbe Kuerzel mitzuschicken ist harmlos). Ein archiviertes Fach ist schreibgeschuetzt.
     *
     * @throws CodedException `subject_not_found` (404), `subject_archived` (409), `invalid_request` (400)
     */
    public function update(Actor $actor, string $id, mixed $name, mixed $claimedCode = null): Subject
    {
        $before = $clean = null;
        Transactions::run(function () use ($id, $name, $claimedCode, $actor, &$before, &$clean): void {
            $before = $this->lockedSubject($id);
            if ($before->archived) {
                throw new CodedException('subject_archived', 409, 'Das Fach ist archiviert und kann nicht geändert werden. Bitte zuerst wiederherstellen.');
            }
            if ($claimedCode !== null && (!is_string($claimedCode) || Text::clean($claimedCode) !== $before->code)) {
                throw new CodedException('invalid_request', 400, 'Das Kürzel eines Fachs lässt sich nicht ändern.');
            }
            $clean = SubjectInput::cleanName($name);
            $this->repository->rename($id, $clean);
            $this->audit->record($actor, AuditLog::SUBJECT_UPDATED, 'subject', $id, ['code' => $before->code, 'old_name' => $before->name, 'name' => $clean]);
        });
        return new Subject($id, $before->code, $clean);
    }

    /**
     * Archiviert ein Fach: es bleibt lesbar, sein Kuerzel belegt, aber es ist schreibgeschuetzt und nicht waehlbar.
     * Geht mit und ohne Verknuepfungen.
     *
     * @throws CodedException `subject_not_found` (404), `subject_already_archived` (409)
     */
    public function archive(Actor $actor, string $id): Subject
    {
        return $this->setArchived($actor, $id, true);
    }

    /**
     * Stellt ein archiviertes Fach wieder her. Sein Kuerzel war die ganze Zeit belegt, eine erneute Eindeutigkeitspruefung
     * ist daher nicht noetig.
     *
     * @throws CodedException `subject_not_found` (404), `subject_not_archived` (409)
     */
    public function restore(Actor $actor, string $id): Subject
    {
        return $this->setArchived($actor, $id, false);
    }

    /**
     * Loescht ein Fach endgueltig, solange nichts an ihm haengt (auch ein archiviertes). Sonst wird abgelehnt und auf das
     * Archivieren verwiesen. Danach ist das Kuerzel wieder frei.
     *
     * @throws CodedException `subject_not_found` (404), `subject_linked` (409)
     */
    public function delete(Actor $actor, string $id): void
    {
        Transactions::run(function () use ($id, $actor): void {
            $subject = $this->lockedSubject($id);
            $this->linkGuards->assertDeletable($id);
            $this->repository->delete($id);
            $this->audit->record($actor, AuditLog::SUBJECT_DELETED, 'subject', $id, ['code' => $subject->code, 'name' => $subject->name]);
        });
    }

    private function setArchived(Actor $actor, string $id, bool $archived): Subject
    {
        $subject = null;
        Transactions::run(function () use ($id, $archived, $actor, &$subject): void {
            $subject = $this->lockedSubject($id);
            if ($subject->archived === $archived) {
                throw $archived
                    ? new CodedException('subject_already_archived', 409, 'Das Fach ist bereits archiviert.')
                    : new CodedException('subject_not_archived', 409, 'Das Fach ist nicht archiviert.');
            }
            $this->repository->setArchived($id, $archived);
            $this->audit->record($actor, $archived ? AuditLog::SUBJECT_ARCHIVED : AuditLog::SUBJECT_RESTORED, 'subject', $id, ['code' => $subject->code, 'name' => $subject->name]);
        });
        return new Subject($id, $subject->code, $subject->name, $archived);
    }

    /**
     * Das Fach, fuer die Dauer der laufenden Transaktion gesperrt, damit gleichzeitiges Bearbeiten, Archivieren und
     * Loeschen sich nicht ueberholt.
     *
     * @throws CodedException `subject_not_found` (404)
     */
    private function lockedSubject(string $id): Subject
    {
        return $this->repository->find($id, lock: true)
            ?? throw new CodedException('subject_not_found', 404, 'Das Fach gibt es nicht.');
    }
}
