<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Database\MysqlError;
use TeacherOS\Shared\Database\Transactions;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Uuid;

/**
 * Faecher ansehen und anlegen (Ticket #62, GLOSSARY.md: Fach). Ein Fach gilt fuer die ganze Schulinstanz, unabhaengig vom
 * Schuljahr; sein Kuerzel ist eindeutig (ohne Gross-/Kleinschreibung) und danach unveraenderlich. Spaetere Module
 * (Fachgruppen) fragen die waehlbaren Faecher bei {@see self::selectable()} ab.
 */
final class Subjects
{
    public function __construct(
        private readonly SubjectRepository $repository = new SubjectRepository(),
        private readonly AuditLog $audit = new AuditLog(),
    ) {
    }

    /**
     * Alle Faecher, natuerlich nach Kuerzel sortiert (D1, D2, D10; Gross-/Kleinschreibung egal).
     *
     * @return list<Subject>
     */
    public function list(): array
    {
        $subjects = $this->repository->all();
        usort($subjects, static fn (Subject $a, Subject $b): int => strnatcasecmp($a->code, $b->code) ?: strcmp($a->code, $b->code));
        return $subjects;
    }

    /**
     * Die fuer neue Zuordnungen waehlbaren Faecher (Fachgruppen waehlen ihr Fach hier), sortiert wie {@see self::list()}.
     * Vorerst sind es alle Faecher.
     *
     * @return list<Subject>
     */
    public function selectable(): array
    {
        return $this->list();
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
}
