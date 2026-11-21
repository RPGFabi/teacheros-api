<?php

declare(strict_types=1);

namespace TeacherOS\Tests\TeachingGroups;

use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;
use TeacherOS\Shared\Uuid;

/**
 * Die Pruefungen, die die Fachgruppen an den Erweiterungspunkten anderer Module anmelden (Ticket #64): Ein Schuljahr mit
 * Fachgruppen laesst sich nicht loeschen (`SchoolYearGuard`), eine Klasse in einer Fachgruppe gilt als verknuepft
 * (`ClassLinkGuard`). Ueber HTTP gegen php-cgi + Test-DB, mit den Registrierungen der echten Anwendung.
 */
final class TeachingGroupGuardsTest extends TestCase
{
    private const ADMIN = '64646464-0000-4564-8564-000000000011';

    private HttpTestApp $app;
    private string $year;
    private string $subject;
    private string $class7a;
    private string $class7b;
    private string $group;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->resetSchoolYears();
        $this->app->resetSubjects();
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        $this->year = $this->post('/api/school-years', ['starts_on' => '2090-09-01', 'ends_on' => '2091-07-31'])['json']['school_year']['id'];
        $this->subject = $this->post('/api/subjects', ['code' => 'Ma', 'name' => 'Mathematik'])['json']['subject']['id'];
        $this->class7a = $this->post('/api/classes', ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7a'])['json']['class']['id'];
        $this->class7b = $this->post('/api/classes', ['school_year_id' => $this->year, 'grade_level' => 7, 'name' => '7b'])['json']['class']['id'];
        $r = $this->post('/api/teaching-groups', ['school_year_id' => $this->year, 'subject_id' => $this->subject, 'name' => 'Ma 7', 'class_ids' => [$this->class7a]]);
        $this->assertSame(201, $r['status'], $r['body']);
        $this->group = $r['json']['teaching_group']['id'];
    }

    /** @return array{status:int, body:string, json:?array} */
    private function post(string $uri, array $body = []): array
    {
        return $this->app->request('POST', $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::ADMIN)], $body);
    }

    public function testEineKlasseInEinerFachgruppeWirdNichtGeloeschtUndAufArchivierenVerwiesen(): void
    {
        $r = $this->post('/api/classes/' . $this->class7a . '/delete');

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('class_linked', $r['json']['code']);
        $this->assertStringContainsString('Fachgruppen', $r['json']['error']);
        $this->assertStringContainsString('archivieren', $r['json']['error']);
        $this->assertCount(2, $this->app->classes());
    }

    public function testNurDieKlasseInDerFachgruppeIstVerknuepft(): void
    {
        $this->assertSame(200, $this->post('/api/classes/' . $this->class7b . '/delete')['status']);
        $this->assertCount(1, $this->app->classes());
    }

    public function testEineKlasseInEinerFachgruppeLaesstSichArchivierenBleibtDarinUndIstDannNochImmerNichtLoeschbar(): void
    {
        $this->assertSame(200, $this->post('/api/classes/' . $this->class7a . '/archive')['status']);

        $group = $this->app->request('GET', '/api/teaching-groups/' . $this->group, ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access(self::ADMIN)])['json']['teaching_group'];
        $this->assertSame([[$this->class7a, true]], array_map(static fn (array $c): array => [$c['id'], $c['archived']], $group['classes']));
        $this->assertSame('class_linked', $this->post('/api/classes/' . $this->class7a . '/delete')['json']['code']);
    }

    public function testEinSchuljahrMitFachgruppenWirdNichtGeloescht(): void
    {
        $r = $this->post('/api/school-years/' . $this->year . '/delete');

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertContains($r['json']['code'], ['school_year_has_classes', 'school_year_has_teaching_groups']);
        $this->assertCount(1, $this->app->schoolYears());
        $this->assertCount(1, $this->app->teachingGroups());
    }

    public function testDerEinwandDerFachgruppenNenntFachgruppenAlsGrund(): void
    {
        // Ohne Klassen im Schuljahr (nur ueber die Datenbank herstellbar) bleibt allein der Einwand der Fachgruppen.
        $this->app->resetTeachingGroups();
        $this->app->resetClasses();
        $this->app->pdo()->prepare('INSERT INTO `teaching_groups` (`id`, `school_year_id`, `subject_id`, `name`, `name_key`) VALUES (?, ?, ?, ?, ?)')
            ->execute([Uuid::v4(), $this->year, $this->subject, 'Ma 7', 'ma 7']);

        $r = $this->post('/api/school-years/' . $this->year . '/delete');

        $this->assertSame(409, $r['status'], $r['body']);
        $this->assertSame('school_year_has_teaching_groups', $r['json']['code']);
        $this->assertStringContainsString('Fachgruppen', $r['json']['error']);
        $this->assertCount(1, $this->app->schoolYears());
    }

    public function testDerZeitraumEinesSchuljahresMitFachgruppenBleibtAenderbar(): void
    {
        $r = $this->post('/api/school-years/' . $this->year . '/update', ['starts_on' => '2090-08-15', 'ends_on' => '2091-07-31']);

        $this->assertSame(200, $r['status'], $r['body']);
    }
}
