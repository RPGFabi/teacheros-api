<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Assignments;

/**
 * Das, was die {@see AssignmentRules} von einer bestehenden Zuordnung wissen muessen, gleich fuer jede Art von Zuordnung
 * (Fachgruppe, Klasse): Kennung, die Lehrkraft (Kennung ihres Kontos) und die {@see Validity}.
 */
final class Assignment
{
    public function __construct(
        public readonly string $id,
        public readonly string $teacherId,
        public readonly Validity $validity,
    ) {
    }
}
