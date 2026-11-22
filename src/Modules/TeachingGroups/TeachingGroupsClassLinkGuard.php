<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use Hoelzle\Core\Database\DB;
use TeacherOS\Modules\Classes\ClassLinkGuard;

/**
 * Verknuepfung der Fachgruppen mit einer Klasse (Ticket #64): Eine Klasse, die in einer Fachgruppe ist (auch in einer
 * spaeter archivierten Klasse), gilt als verknuepft und wird nicht geloescht, sondern archiviert (GLOSSARY.md: Archivierung).
 * Der Fremdschluessel `teaching_group_classes.class_id` sichert dasselbe auf der Datenbank ab.
 */
final class TeachingGroupsClassLinkGuard implements ClassLinkGuard
{
    public function label(): string
    {
        return 'Fachgruppen';
    }

    public function isLinked(string $classId): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM `teaching_group_classes` WHERE `class_id` = :id LIMIT 1');
        $stmt->execute(['id' => $classId]);
        return $stmt->fetchColumn() !== false;
    }
}
