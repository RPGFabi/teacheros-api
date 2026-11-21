<?php

declare(strict_types=1);

namespace TeacherOS\Modules\ClassLeaders;

use TeacherOS\Modules\Classes\ClassLinkGuard;

/**
 * Verknuepfung der Klassenleitung an der Klasse (Tickets #57, #68; GLOSSARY.md: Archivierung): Eine Klasse, der mindestens
 * eine Klassenleitung zugeordnet ist oder war (auch eine beendete ist Historie), gilt als verknuepft, wird also nicht geloescht,
 * sondern archiviert. Der Fremdschluessel `class_leaders.class_id` sichert dasselbe auf der Datenbank ab; diese Pruefung
 * liefert vorher den verstaendlichen Fehlercode (`class_linked`).
 */
final class ClassLeadersClassLinkGuard implements ClassLinkGuard
{
    public function __construct(private readonly ClassLeaderRepository $repository = new ClassLeaderRepository())
    {
    }

    public function label(): string
    {
        return 'Klassenleitung';
    }

    public function isLinked(string $classId): bool
    {
        return $this->repository->classHasLeaders($classId);
    }
}
