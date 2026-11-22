<?php

declare(strict_types=1);

namespace TeacherOS\Tests\TeacherAssignments;

use TeacherOS\Tests\Support\AssignmentEndpointsTestCase;

/**
 * Lehrkraft-Zuordnung zu Fachgruppen (Ticket #67) ueber HTTP gegen php-cgi + Test-DB:
 * GET /api/teacher-assignments?teaching_group_id= (`teaching_groups.view`), GET .../teachers, POST /api/teacher-assignments,
 * POST .../:id/end, POST .../:id/delete (`teacher_assignments.manage`). Die Faelle, die fuer jede Zuordnung gelten, stehen in
 * {@see AssignmentEndpointsTestCase}; hier steht, was an der Fachgruppe haengt, und die Liste der waehlbaren Lehrkraefte.
 */
final class TeacherAssignmentsEndpointsTest extends AssignmentEndpointsTestCase
{
    private ?string $subject = null;

    protected function idPrefix(): string
    {
        return '67676767-0000-4567-8567-';
    }

    protected function uri(): string
    {
        return '/api/teacher-assignments';
    }

    protected function datedUri(): string
    {
        return '/api/test/dated-teacher-assignments';
    }

    protected function observeUri(): string
    {
        return '/api/test/observe-teacher-assignments';
    }

    protected function parentKey(): string
    {
        return 'teaching_group_id';
    }

    protected function itemKey(): string
    {
        return 'teacher_assignment';
    }

    protected function listKey(): string
    {
        return 'teacher_assignments';
    }

    protected function viewPermission(): string
    {
        return 'teaching_groups.view';
    }

    protected function table(): string
    {
        return 'teacher_assignments';
    }

    protected function limitSetting(): string
    {
        return 'max_teachers_per_teaching_group';
    }

    protected function schoolYearCode(): string
    {
        return 'school_year_has_teacher_assignments';
    }

    protected function parentNotFoundCode(): string
    {
        return 'teaching_group_not_found';
    }

    protected function parentArchivedCode(): string
    {
        return 'teaching_group_archived';
    }

    protected function notFoundCode(): string
    {
        return 'teacher_assignment_not_found';
    }

    protected function parentLinkedCode(): string
    {
        return 'teaching_group_linked';
    }

    protected function linkLabel(): string
    {
        return 'Lehrkraft-Zuordnung';
    }

    protected function parentUri(): string
    {
        return '/api/teaching-groups';
    }

    protected function parentRows(): array
    {
        return $this->app->teachingGroups();
    }

    protected function migrationFile(): string
    {
        return '0024_teacher_assignments.php';
    }

    protected function resetMore(): void
    {
        $this->app->resetSubjects();
        $this->subject = null;
    }

    protected function extraManageRequests(): array
    {
        return [fn (string $sub): array => $this->request('GET', $this->uri() . '/teachers', null, $sub)];
    }

    protected function createParent(string $yearId, string $name): string
    {
        $this->subject ??= $this->post('/api/subjects', ['code' => 'Ma', 'name' => 'Mathematik'])['json']['subject']['id'];
        $class = $this->post('/api/classes', ['school_year_id' => $yearId, 'grade_level' => 7, 'name' => $name]);
        $this->assertSame(201, $class['status'], $class['body']);
        $group = $this->post('/api/teaching-groups', ['school_year_id' => $yearId, 'subject_id' => $this->subject, 'name' => "Ma $name", 'class_ids' => [$class['json']['class']['id']]]);
        $this->assertSame(201, $group['status'], $group['body']);
        return $group['json']['teaching_group']['id'];
    }

    // --- Rechte, Katalog und Auswahl der Lehrkraefte ---

    public function testKatalogFuehrtDasRechtInDerGruppeStammdaten(): void
    {
        $byKey = array_column($this->request('GET', '/api/permissions')['json']['permissions'], null, 'key');

        $this->assertSame(['key' => 'teacher_assignments.manage', 'name' => 'Lehrkraft-Zuordnungen verwalten', 'group' => 'Stammdaten'], $byKey['teacher_assignments.manage']);
    }

    public function testKeineStandardrolleHatDasRechtVoreingestellt(): void
    {
        foreach ($this->request('GET', '/api/roles')['json']['roles'] as $role) {
            if ($role['key'] !== 'schuladmin') {
                $this->assertNotContains('teacher_assignments.manage', $role['permissions'], $role['key']);
            }
        }
        $this->assertContains('teacher_assignments.manage', $this->request('GET', '/api/me')['json']['permissions']);
    }

    public function testOhneTokenKommt401AuchBeiDerAuswahlDerLehrkraefte(): void
    {
        $this->assertSame(401, $this->app->request('GET', $this->uri() . '/teachers')['status']);
    }

    public function testDieAuswahlBrauchtTeacherAssignmentsManageUndNenntEmailUndKontostatus(): void
    {
        $r = $this->request('GET', $this->uri() . '/teachers', null, $this->manager);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(
            [
                ['id' => $this->teacherA, 'login_name' => 'lehrkraft0', 'email' => 'lehrkraft0@example.test', 'status' => 'active'],
                ['id' => $this->teacherB, 'login_name' => 'lehrkraft1', 'email' => 'lehrkraft1@example.test', 'status' => 'active'],
                ['id' => $this->teacherC, 'login_name' => 'lehrkraft2', 'email' => 'lehrkraft2@example.test', 'status' => 'active'],
            ],
            $r['json']['teachers'],
        );
        $this->assertSame(403, $this->request('GET', $this->uri() . '/teachers', null, $this->viewer)['status'], 'wer nur lesen darf, bekommt keine E-Mail');
    }

    public function testWaehlbareLehrkraeftesindKontenMitDerStandardrolleLehrkraftOhneDeaktivierte(): void
    {
        $invited = $this->idPrefix() . '0000000000d4';
        $inactive = $this->idPrefix() . '0000000000e5';
        $secretary = $this->idPrefix() . '0000000000f6';
        $this->app->createAccount($invited, 'invited', 'eingeladen@example.test', 'eingeladen');
        $this->app->assignRole($invited, 'lehrkraft');
        $this->app->createAccount($inactive, 'inactive', 'gesperrt@example.test', 'gesperrt');
        $this->app->assignRole($inactive, 'lehrkraft');
        $this->app->createAccount($secretary, 'active', 'sekretariat@example.test', 'sekretariat');
        $this->app->assignRole($secretary, 'sekretariat');

        $r = $this->request('GET', $this->uri() . '/teachers');

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(
            [
                ['id' => $invited, 'login_name' => 'eingeladen', 'email' => 'eingeladen@example.test', 'status' => 'invited'],
                ['id' => $this->teacherA, 'login_name' => 'lehrkraft0', 'email' => 'lehrkraft0@example.test', 'status' => 'active'],
                ['id' => $this->teacherB, 'login_name' => 'lehrkraft1', 'email' => 'lehrkraft1@example.test', 'status' => 'active'],
                ['id' => $this->teacherC, 'login_name' => 'lehrkraft2', 'email' => 'lehrkraft2@example.test', 'status' => 'active'],
            ],
            $r['json']['teachers'],
        );
    }
}
