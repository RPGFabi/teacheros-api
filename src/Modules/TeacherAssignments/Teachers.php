<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AccountRepository;
use TeacherOS\Shared\Security\StandardRoles;

/**
 * Wer Lehrkraft ist und zugeordnet werden kann (GLOSSARY.md: Lehrkraft, ADR 0007): ein Konto mit der Standardrolle
 * Lehrkraft, das nicht deaktiviert ist (eingeladen oder aktiv). Eigene Rollen machen ein Konto nicht zur Lehrkraft.
 * Rolle und Status zaehlen nur beim Anlegen einer Zuordnung ({@see self::assertSelectable()}); die Ausgabe bestehender
 * Zuordnungen zeigt jedes Konto mit seinem heutigen Status ({@see self::byIds()}). Gemeinsam fuer Fachgruppen (#67) und
 * Klassenleitungen (#68).
 */
final class Teachers
{
    public function __construct(private readonly AccountRepository $accounts = new AccountRepository())
    {
    }

    /**
     * Die waehlbaren Lehrkraefte fuer das Formular, nach Anmeldename.
     *
     * @return list<Teacher>
     */
    public function selectable(): array
    {
        return array_map(Teacher::fromRow(...), $this->accounts->assignableWithRole(StandardRoles::LEHRKRAFT));
    }

    /**
     * Das Konto muss jetzt eine waehlbare Lehrkraft sein. In einer Transaktion wird die Zeile des Kontos gesperrt, damit ein
     * gleichzeitiges Deaktivieren nicht dazwischen kommt.
     *
     * @throws CodedException `teacher_not_selectable` (409), wenn es das Konto nicht gibt, es nicht die Rolle Lehrkraft hat oder deaktiviert ist
     */
    public function assertSelectable(string $accountId): void
    {
        if (!$this->accounts->isAssignableWithRole($accountId, StandardRoles::LEHRKRAFT, lock: true)) {
            throw new CodedException('teacher_not_selectable', 409, 'Das Konto gibt es nicht, es hat nicht die Rolle Lehrkraft oder es ist deaktiviert.');
        }
    }

    /**
     * Die Lehrkraefte zu diesen Konto-Kennungen, jedes mit seinem heutigen Status. Ein Konto, das es nicht mehr gibt,
     * erscheint nur mit Kennung (deaktiviert, ohne Namen).
     *
     * @param list<string> $accountIds
     * @return array<string,Teacher> nach Kennung
     */
    public function byIds(array $accountIds): array
    {
        $rows = $this->accounts->summaries(array_values(array_unique($accountIds)));
        $teachers = [];
        foreach ($accountIds as $id) {
            $teachers[$id] = isset($rows[$id]) ? Teacher::fromRow($rows[$id]) : new Teacher($id, '', null, 'inactive');
        }
        return $teachers;
    }
}
