<?php

declare(strict_types=1);

namespace TeacherOS\Modules\ClassLeaders;

use TeacherOS\Modules\Classes\ClassLinkGuard;
use TeacherOS\Shared\Assignments\AssignmentRepository;

/**
 * Verknuepfung der Klassenleitung an der Klasse (Tickets #57, #68; GLOSSARY.md: Archivierung): Eine Klasse, der mindestens
 * eine Klassenleitung zugeordnet ist oder war (auch eine beendete ist Historie), gilt als verknuepft, wird also nicht geloescht,
 * sondern archiviert. Der Fremdschluessel `class_leaders.class_id` sichert dasselbe auf der Datenbank ab; diese Pruefung
 * liefert vorher den verstaendlichen Fehlercode (`class_linked`).
 */
final class ClassLeadersClassLinkGuard implements ClassLinkGuard
{
    private readonly AssignmentRepository $repository;

    public function __construct(?AssignmentRepository $repository = null)
    {
        $this->repository = $repository ?? new AssignmentRepository(ClassLeaderKind::get());
    }

    public function label(): string
    {
        return 'Klassenleitung';
    }

    public function isLinked(string $id): bool
    {
        return $this->repository->parentHasAssignments($id);
    }
}
