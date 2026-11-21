<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Modules\TeachingGroups\TeachingGroupLinkGuard;

/**
 * Verknuepfung der Lehrkraft-Zuordnung an der Fachgruppe (Tickets #65, #67; GLOSSARY.md: Archivierung): Eine Fachgruppe, der
 * mindestens eine Lehrkraft zugeordnet ist oder war (auch eine beendete Zuordnung ist Historie), gilt als verknuepft, wird also
 * nicht geloescht, sondern archiviert. Der Fremdschluessel `teacher_assignments.teaching_group_id` sichert dasselbe auf der
 * Datenbank ab; diese Pruefung liefert vorher den verstaendlichen Fehlercode (`teaching_group_linked`).
 */
final class TeacherAssignmentsLinkGuard implements TeachingGroupLinkGuard
{
    public function __construct(private readonly TeacherAssignmentRepository $repository = new TeacherAssignmentRepository())
    {
    }

    public function label(): string
    {
        return 'Lehrkraft-Zuordnung';
    }

    public function isLinked(string $teachingGroupId): bool
    {
        return $this->repository->groupHasAssignments($teachingGroupId);
    }
}
