<?php

declare(strict_types=1);

namespace TeacherOS\Modules\ClassLeaders;

use TeacherOS\Modules\SchoolSettings\SchoolLimits;
use TeacherOS\Shared\Assignments\AssignmentKind;
use TeacherOS\Shared\Audit\AuditLog;

/**
 * Die Klassenleitung (Ticket #68, GLOSSARY.md: Klassenleitung) als {@see AssignmentKind}: Tabelle `class_leaders` am
 * Elternobjekt `classes`, Audit `class_leader.*`, Obergrenze {@see SchoolLimits::maxClassLeadersPerClass()}.
 */
final class ClassLeaderKind
{
    public static function get(): AssignmentKind
    {
        return new AssignmentKind(
            table: 'class_leaders',
            parentTable: 'classes',
            parentColumn: 'class_id',
            objectType: 'class_leader',
            collectionKey: 'class_leaders',
            parentLabel: 'die Klasse (class_id)',
            auditCreated: AuditLog::CLASS_LEADER_CREATED,
            auditEnded: AuditLog::CLASS_LEADER_ENDED,
            auditDeleted: AuditLog::CLASS_LEADER_DELETED,
            parentNotFoundCode: 'class_not_found',
            parentNotFoundMessage: 'Die Klasse gibt es nicht.',
            parentArchivedCode: 'class_archived',
            parentArchivedMessage: 'Die Klasse ist archiviert. Ihr lässt sich keine Klassenleitung neu zuordnen; bestehende Klassenleitungen bleiben und lassen sich beenden.',
            notFoundCode: 'class_leader_not_found',
            notFoundMessage: 'Die Klassenleitung gibt es nicht.',
            schoolYearCode: 'school_year_has_class_leaders',
            schoolYearOutsideMessage: 'Im neuen Zeitraum würden Klassenleitungen herausfallen. Bitte zuerst die Klassenleitungen beenden oder löschen.',
            schoolYearLossMessage: 'Das neue Schuljahresende liegt vor heute, aber Klassenleitungen laufen schon und gelten bis zum Schuljahresende: Ihre bereits gegoltenen Tage würden gekürzt. Bitte zuerst die Klassenleitungen beenden.',
            limit: static fn (SchoolLimits $limits): int => $limits->maxClassLeadersPerClass(),
        );
    }
}
