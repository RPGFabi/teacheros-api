<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Modules\TeachingGroups\TeachingGroupLinkGuard;
use TeacherOS\Shared\Assignments\AssignmentRepository;

/**
 * Verknuepfung der Lehrkraft-Zuordnung an der Fachgruppe (Tickets #65, #67; GLOSSARY.md: Archivierung): Eine Fachgruppe, der
 * mindestens eine Lehrkraft zugeordnet ist oder war (auch eine beendete Zuordnung ist Historie), gilt als verknuepft, wird also
 * nicht geloescht, sondern archiviert. Der Fremdschluessel `teacher_assignments.teaching_group_id` sichert dasselbe auf der
 * Datenbank ab; diese Pruefung liefert vorher den verstaendlichen Fehlercode (`teaching_group_linked`).
 */
final class TeacherAssignmentsLinkGuard implements TeachingGroupLinkGuard
{
    private readonly AssignmentRepository $repository;

    public function __construct(?AssignmentRepository $repository = null)
    {
        $this->repository = $repository ?? new AssignmentRepository(TeacherAssignmentKind::get());
    }

    public function label(): string
    {
        return 'Lehrkraft-Zuordnung';
    }

    public function isLinked(string $id): bool
    {
        return $this->repository->parentHasAssignments($id);
    }
}
