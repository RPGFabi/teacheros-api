<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

use TeacherOS\Modules\SchoolSettings\SchoolLimits;
use TeacherOS\Shared\Assignments\AssignmentKind;
use TeacherOS\Shared\Audit\AuditLog;

/**
 * Die Lehrkraft-Zuordnung zu einer Fachgruppe (Ticket #67, GLOSSARY.md: Lehrkraft-Zuordnung) als {@see AssignmentKind}: Tabelle
 * `teacher_assignments` am Elternobjekt `teaching_groups`, Audit `teacher_assignment.*`, Obergrenze
 * {@see SchoolLimits::maxTeachersPerTeachingGroup()}.
 */
final class TeacherAssignmentKind
{
    public static function get(): AssignmentKind
    {
        return new AssignmentKind(
            table: 'teacher_assignments',
            parentTable: 'teaching_groups',
            parentColumn: 'teaching_group_id',
            objectType: 'teacher_assignment',
            collectionKey: 'teacher_assignments',
            parentLabel: 'die Fachgruppe (teaching_group_id)',
            auditCreated: AuditLog::TEACHER_ASSIGNMENT_CREATED,
            auditEnded: AuditLog::TEACHER_ASSIGNMENT_ENDED,
            auditDeleted: AuditLog::TEACHER_ASSIGNMENT_DELETED,
            parentNotFoundCode: 'teaching_group_not_found',
            parentNotFoundMessage: 'Die Fachgruppe gibt es nicht.',
            parentArchivedCode: 'teaching_group_archived',
            parentArchivedMessage: 'Die Fachgruppe ist archiviert. Ihr lassen sich keine Lehrkräfte neu zuordnen; bestehende Zuordnungen bleiben und lassen sich beenden.',
            notFoundCode: 'teacher_assignment_not_found',
            notFoundMessage: 'Die Zuordnung gibt es nicht.',
            schoolYearCode: 'school_year_has_teacher_assignments',
            schoolYearOutsideMessage: 'Im neuen Zeitraum würden Lehrkraft-Zuordnungen von Fachgruppen herausfallen. Bitte zuerst die Zuordnungen beenden oder löschen.',
            schoolYearLossMessage: 'Das neue Schuljahresende liegt vor heute, aber Lehrkraft-Zuordnungen von Fachgruppen laufen schon und gelten bis zum Schuljahresende: Ihre bereits gegoltenen Tage würden gekürzt. Bitte zuerst die Zuordnungen beenden.',
            limit: static fn (SchoolLimits $limits): int => $limits->maxTeachersPerTeachingGroup(),
        );
    }
}
