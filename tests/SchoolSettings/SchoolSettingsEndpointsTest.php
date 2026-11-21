<?php

declare(strict_types=1);

namespace TeacherOS\Tests\SchoolSettings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * Schuleinstellungen (Ticket #66, GLOSSARY.md: Schuleinstellung) ueber HTTP gegen php-cgi + Test-DB:
 * GET /api/school-settings und POST /api/school-settings/update, beide mit `school_settings.manage`.
 * Die Probe-Route /api/test/school-limits steht fuer ein spaeteres Zuordnungs-Modul (#67, #68), das die Werte
 * ueber den Dienst `SchoolLimits` liest, ohne die Speicherung zu kennen.
 */
final class SchoolSettingsEndpointsTest extends TestCase
{
    private const ADMIN = '66666666-0000-4666-8666-000000000001';
    private const MANAGER = '66666666-0000-4666-8666-000000000002';
    private const NOBODY = '66666666-0000-4666-8666-000000000003';
    private const OTHER_RIGHTS = '66666666-0000-4666-8666-000000000004';
    private const URI = '/api/school-settings';
    private const UPDATE = '/api/school-settings/update';
    private const TEACHERS = 'max_teachers_per_subject_group';
    private const LEADERS = 'max_class_leaders_per_class';

    private HttpTestApp $app;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->app->resetSchoolSettings();
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        $this->app->createAccount(self::MANAGER, 'active');
        $this->app->grantPermission(self::MANAGER, 'school_settings.manage');
        $this->app->createAccount(self::NOBODY, 'active');
        $this->app->createAccount(self::OTHER_RIGHTS, 'active');
        foreach (['school_years.manage', 'classes.manage', 'roles.manage'] as $permission) {
            $this->app->grantPermission(self::OTHER_RIGHTS, $permission);
        }
    }

    private function headers(string $sub): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . TestTokens::access($sub)];
    }

    private function read(string $sub = self::ADMIN): array
    {
        return $this->app->request('GET', self::URI, $this->headers($sub));
    }

    private function update(array $body, string $sub = self::ADMIN): array
    {
        return $this->app->request('POST', self::UPDATE, $this->headers($sub), $body);
    }

    /** Die Werte aus der Antwort als Schluessel => Wert. */
    private static function values(array $response): array
    {
        return array_column($response['json']['school_settings'], 'value', 'key');
    }

    // --- Recht ---

    public function testKatalogFuehrtDasRechtInDerGruppeStammdaten(): void
    {
        $r = $this->app->request('GET', '/api/permissions', $this->headers(self::ADMIN));

        $byKey = array_column($r['json']['permissions'], null, 'key');
        $this->assertSame(['key' => 'school_settings.manage', 'name' => 'Schuleinstellungen verwalten', 'group' => 'Stammdaten'], $byKey['school_settings.manage']);
    }

    public function testSchuladminHatDasRechtUeberDieWildcardKeineStandardrolleVoreingestellt(): void
    {
        $this->assertContains('school_settings.manage', $this->app->request('GET', '/api/me', $this->headers(self::ADMIN))['json']['permissions']);

        foreach ($this->app->request('GET', '/api/roles', $this->headers(self::ADMIN))['json']['roles'] as $role) {
            if ($role['key'] !== 'schuladmin') {
                $this->assertNotContains('school_settings.manage', $role['permissions'], $role['key']);
            }
        }
    }

    public function testOhneTokenKommt401(): void
    {
        $this->assertSame(401, $this->app->request('GET', self::URI)['status']);
        $this->assertSame(401, $this->app->request('POST', self::UPDATE, [], [self::TEACHERS => 3])['status']);
    }

    public function testLesenUndAendernBrauchenDasRechtSonst403Forbidden(): void
    {
        foreach ([self::NOBODY, self::OTHER_RIGHTS] as $sub) {
            foreach ([$this->read($sub), $this->update([self::TEACHERS => 3], $sub)] as $r) {
                $this->assertSame(403, $r['status'], $sub);
                $this->assertSame('forbidden', $r['json']['code'], $sub);
            }
        }
        $this->assertSame([self::TEACHERS => 2, self::LEADERS => 2], self::values($this->read()), 'ein abgelehnter Versuch aendert nichts');
        $this->assertSame(200, $this->read(self::MANAGER)['status']);
        $this->assertSame(200, $this->update([self::TEACHERS => 3], self::MANAGER)['status']);
    }

    // --- Lesen ---

    public function testOhneGespeicherteWerteGiltJeZwei(): void
    {
        $r = $this->read();

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame([self::TEACHERS => 2, self::LEADERS => 2], self::values($r));
    }

    public function testAntwortNenntJeEinstellungStandardUndGrenzen(): void
    {
        $settings = array_column($this->read()['json']['school_settings'], null, 'key');

        $this->assertSame(['key' => self::TEACHERS, 'value' => 2, 'default' => 2, 'min' => 1, 'max' => 50], $settings[self::TEACHERS]);
        $this->assertSame(['key' => self::LEADERS, 'value' => 2, 'default' => 2, 'min' => 1, 'max' => 10], $settings[self::LEADERS]);
    }

    // --- Aendern ---

    public function testAendernSpeichertDenWertUndLiefertBeideWerte(): void
    {
        $r = $this->update([self::TEACHERS => 5]);

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame([self::TEACHERS => 5, self::LEADERS => 2], self::values($r));
        $this->assertSame([self::TEACHERS => 5, self::LEADERS => 2], self::values($this->read()), 'bleibt gespeichert');
    }

    public function testBeideWerteLassenSichUnabhaengigVoneinanderAendern(): void
    {
        $this->update([self::TEACHERS => 4, self::LEADERS => 3]);
        $this->update([self::LEADERS => 1]);

        $this->assertSame([self::TEACHERS => 4, self::LEADERS => 1], self::values($this->read()));
    }

    public function testGrenzenGeltenEinschliesslich(): void
    {
        $this->assertSame(200, $this->update([self::TEACHERS => 1, self::LEADERS => 1])['status']);
        $this->assertSame(200, $this->update([self::TEACHERS => 50, self::LEADERS => 10])['status']);
        $this->assertSame([self::TEACHERS => 50, self::LEADERS => 10], self::values($this->read()));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function ungueltigeEingaben(): iterable
    {
        yield 'leere Anfrage' => [[]];
        yield 'Null' => [[self::TEACHERS => 0]];
        yield 'negativ' => [[self::LEADERS => -1]];
        yield 'ueber der Obergrenze der Lehrkraefte' => [[self::TEACHERS => 51]];
        yield 'ueber der Obergrenze der Klassenleitungen' => [[self::LEADERS => 11]];
        yield 'als Text' => [[self::TEACHERS => '3']];
        yield 'als Kommazahl' => [[self::TEACHERS => 2.5]];
        yield 'null' => [[self::TEACHERS => null]];
        yield 'Wahrheitswert' => [[self::TEACHERS => true]];
        yield 'unbekannte Einstellung' => [['something_else' => 3]];
        yield 'gueltiger Wert neben ungueltigem' => [[self::TEACHERS => 3, self::LEADERS => 0]];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('ungueltigeEingaben')]
    public function testUngueltigeEingabeIst400OhneAenderung(array $body): void
    {
        $r = $this->update($body);

        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertSame('invalid_request', $r['json']['code']);
        $this->assertSame([self::TEACHERS => 2, self::LEADERS => 2], self::values($this->read()));
        $this->assertSame([], $this->app->schoolSettingAudit(), 'kein Audit-Eintrag bei Ablehnung');
    }

    // --- Audit ---

    public function testAendernSchreibtAuditEintragMitAltemUndNeuemWert(): void
    {
        $this->update([self::TEACHERS => 5]);
        $this->update([self::TEACHERS => 3]);

        $entries = $this->app->schoolSettingAudit();
        $this->assertCount(2, $entries);
        $this->assertSame('school_setting', $entries[0]['object_type']);
        $this->assertSame(self::TEACHERS, $entries[0]['object_id']);
        $this->assertSame('account', $entries[0]['actor_type']);
        $this->assertSame(self::ADMIN, $entries[0]['actor_account_id']);
        $this->assertSame(['old_value' => 2, 'value' => 5], json_decode($entries[0]['context'], true), 'alter Wert ist der Standard, solange nichts gespeichert ist');
        $this->assertSame(['old_value' => 5, 'value' => 3], json_decode($entries[1]['context'], true));
    }

    public function testAuditEintragJeGeaendertemWertUndNichtFuerUnveraendertes(): void
    {
        $this->update([self::TEACHERS => 2, self::LEADERS => 4]);

        $entries = $this->app->schoolSettingAudit();
        $this->assertCount(1, $entries, 'Lehrkraefte bleibt bei 2: nichts zu protokollieren');
        $this->assertSame(self::LEADERS, $entries[0]['object_id']);
        $this->assertSame(['old_value' => 2, 'value' => 4], json_decode($entries[0]['context'], true));
    }

    // --- Dienst fuer andere Module ---

    public function testDienstLiefertStandardOhneGespeicherteWerte(): void
    {
        $r = $this->app->request('GET', '/api/test/school-limits', $this->headers(self::ADMIN));

        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(['max_teachers_per_subject_group' => 2, 'max_class_leaders_per_class' => 2], $r['json']);
    }

    public function testDienstLiefertDieGeaendertenWerte(): void
    {
        $this->update([self::TEACHERS => 7, self::LEADERS => 3]);

        $r = $this->app->request('GET', '/api/test/school-limits', $this->headers(self::ADMIN));

        $this->assertSame(['max_teachers_per_subject_group' => 7, 'max_class_leaders_per_class' => 3], $r['json']);
    }

    // --- Trennung von der Modulfreigabe ---

    public function testSchuleinstellungenBrauchenKeineModulfreigabe(): void
    {
        $this->app->resetModules();

        $this->assertSame(200, $this->read()['status'], 'core ist immer aktiv; Schuleinstellung und Modulfreigabe sind getrennt');
        $this->update([self::TEACHERS => 3]);
        $this->assertSame([], $this->app->moduleEntitlements(), 'Aendern von Schuleinstellungen beruehrt keine Modulfreigabe');
    }
}
