<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use Hoelzle\Core\Database\DB;
use TeacherOS\Modules\Subjects\SubjectLinkGuard;

/**
 * Verknuepfung der Fachgruppen mit einem Fach (Ticket #64): Ein Fach, zu dem es eine Fachgruppe gibt (in irgendeinem
 * Schuljahr), gilt als verknuepft und wird nicht geloescht, sondern archiviert (GLOSSARY.md: Archivierung). Der
 * Fremdschluessel `teaching_groups.subject_id` sichert dasselbe auf der Datenbank ab.
 */
final class TeachingGroupsSubjectLinkGuard implements SubjectLinkGuard
{
    public function label(): string
    {
        return 'Fachgruppen';
    }

    public function isLinked(string $subjectId): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM `teaching_groups` WHERE `subject_id` = :id LIMIT 1');
        $stmt->execute(['id' => $subjectId]);
        return $stmt->fetchColumn() !== false;
    }
}
