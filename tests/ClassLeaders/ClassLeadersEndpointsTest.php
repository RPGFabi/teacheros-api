<?php

declare(strict_types=1);

namespace TeacherOS\Tests\ClassLeaders;

use TeacherOS\Tests\Support\AssignmentEndpointsTestCase;

/**
 * Klassenleitung (Ticket #68) ueber HTTP gegen php-cgi + Test-DB:
 * GET /api/class-leaders?class_id= (`classes.view`), POST /api/class-leaders, POST .../:id/end, POST .../:id/delete
 * (`teacher_assignments.manage`). Die waehlbaren Lehrkraefte liefert unveraendert GET /api/teacher-assignments/teachers.
 * Die Faelle, die fuer jede Zuordnung gelten, stehen in {@see AssignmentEndpointsTestCase}; hier steht, was an der Klasse haengt.
 */
final class ClassLeadersEndpointsTest extends AssignmentEndpointsTestCase
{
    protected function idPrefix(): string
    {
        return '68686868-0000-4568-8568-';
    }

    protected function uri(): string
    {
        return '/api/class-leaders';
    }

    protected function datedUri(): string
    {
        return '/api/test/dated-class-leaders';
    }

    protected function observeUri(): string
    {
        return '/api/test/observe-class-leaders';
    }

    protected function parentKey(): string
    {
        return 'class_id';
    }

    protected function itemKey(): string
    {
        return 'class_leader';
    }

    protected function listKey(): string
    {
        return 'class_leaders';
    }

    protected function viewPermission(): string
    {
        return 'classes.view';
    }

    protected function table(): string
    {
        return 'class_leaders';
    }

    protected function limitSetting(): string
    {
        return 'max_class_leaders_per_class';
    }

    protected function schoolYearCode(): string
    {
        return 'school_year_has_class_leaders';
    }

    protected function parentNotFoundCode(): string
    {
        return 'class_not_found';
    }

    protected function parentArchivedCode(): string
    {
        return 'class_archived';
    }

    protected function notFoundCode(): string
    {
        return 'class_leader_not_found';
    }

    protected function parentLinkedCode(): string
    {
        return 'class_linked';
    }

    protected function linkLabel(): string
    {
        return 'Klassenleitung';
    }

    protected function parentUri(): string
    {
        return '/api/classes';
    }

    protected function parentRows(): array
    {
        return $this->app->classes();
    }

    protected function migrationFile(): string
    {
        return '0025_class_leaders.php';
    }

    protected function createParent(string $yearId, string $name): string
    {
        $r = $this->post('/api/classes', ['school_year_id' => $yearId, 'grade_level' => 7, 'name' => $name]);
        $this->assertSame(201, $r['status'], $r['body']);
        return $r['json']['class']['id'];
    }

    public function testDieObergrenzeDerFachgruppenGiltFuerKlassenleitungenNicht(): void
    {
        $this->assertSame(200, $this->post('/api/school-settings/update', ['max_teachers_per_teaching_group' => 1])['status']);

        $this->created($this->teacherA);
        $this->assertSame(201, $this->assign($this->teacherB)['status']);
    }
}
