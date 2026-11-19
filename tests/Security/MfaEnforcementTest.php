<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Tests\Support\AuthStub;
use TeacherOS\Tests\Support\HttpTestApp;
use TeacherOS\Tests\Support\TestTokens;

/**
 * MFA-Pflicht fuer Mitarbeitende (Ticket #15, ADR 0002 B3 und G2): Konten mit einer Pflichtrolle erhalten ohne zweiten
 * Faktor auf fachlichen Routen 403 `mfa_enrollment_required`; `GET /api/me` bleibt erreichbar. Jede Aenderung der Pflicht
 * wird nach dem Commit an den Anmeldedienst gespiegelt (`mfa-requirement`); scheitert das, bleibt die lokale Durchsetzung.
 * HTTP-Tests gegen MariaDB und den Auth-Stub.
 */
final class MfaEnforcementTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const TARGET = '33333333-3333-4333-8333-333333333333';
    private const OTHER = '55555555-5555-4555-8555-555555555555';

    private HttpTestApp $app;
    private AuthStub $stub;

    protected function setUp(): void
    {
        $this->app = HttpTestApp::boot();
        $this->app->resetAccounts();
        $this->stub = AuthStub::start();
        $this->app->createAccount(self::ADMIN, 'active');
        $this->app->assignRole(self::ADMIN, 'schuladmin');
        $this->app->createAccount(self::TARGET, 'active', 'lehrkraft@schule.test');
        // Damit die fachliche Route nach der MFA-Pruefung mit 200 antwortet und nicht an fehlenden Rechten scheitert.
        $this->app->grantPermission(self::TARGET, 'accounts.view');
    }

    protected function tearDown(): void
    {
        $this->stub->stop();
    }

    private function as(string $sub, string $method, string $uri, bool $usedMfa = false, ?array $json = null): array
    {
        $token = TestTokens::access($sub, ['usedMFA' => $usedMfa]);
        return $this->app->request($method, $uri, ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] + $this->stub->env(), $json);
    }

    private function roleId(string $key): string
    {
        $stmt = $this->app->pdo()->prepare('SELECT `id` FROM `permission_roles` WHERE `role_key` = ?');
        $stmt->execute([$key]);
        return (string) $stmt->fetchColumn();
    }

    private function assign(string $roleKey, string $target = self::TARGET): array
    {
        return $this->as(self::ADMIN, 'POST', "/api/accounts/$target/roles", json: ['role_id' => $this->roleId($roleKey)]);
    }

    private function revoke(string $roleKey, string $target = self::TARGET): array
    {
        return $this->as(self::ADMIN, 'POST', "/api/accounts/$target/roles/{$this->roleId($roleKey)}/revoke");
    }

    /** @return array<string,mixed> */
    private function row(string $id): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM `accounts` WHERE `public_user_id` = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function fachlicheRoute(string $sub = self::TARGET, bool $usedMfa = false): array
    {
        return $this->as($sub, 'GET', '/api/accounts', $usedMfa);
    }

    // ---- Durchsetzung (B3) --------------------------------------------------------------------

    public function testLehrkraftOhneTotpErhaelt403UndNachEinrichtungUndNeuemLoginZugriff(): void
    {
        $this->assertSame(200, $this->assign('lehrkraft')['status']);

        $ohne = $this->fachlicheRoute();
        $this->assertSame(403, $ohne['status']);
        $this->assertSame('mfa_enrollment_required', $ohne['json']['code']);

        // Nach der Einrichtung beim Anmeldedienst und erneutem Login traegt das Token usedMFA=true.
        $mit = $this->fachlicheRoute(usedMfa: true);
        $this->assertSame(200, $mit['status']);
    }

    public function testDieMfaPflichtGehtDerRechtepruefungVor(): void
    {
        $this->app->pdo()->exec("DELETE FROM `permission_user_overrides`");
        $this->assign('lehrkraft');

        $this->assertSame('mfa_enrollment_required', $this->fachlicheRoute()['json']['code']);
        $this->assertSame('forbidden', $this->fachlicheRoute(usedMfa: true)['json']['code']);
    }

    public function testMeBleibtOhneMfaErreichbarUndNenntDieEinrichtung(): void
    {
        $this->assign('lehrkraft');

        $ohne = $this->as(self::TARGET, 'GET', '/api/me');
        $this->assertSame(200, $ohne['status']);
        $this->assertTrue($ohne['json']['account']['mfa_required']);
        $this->assertFalse($ohne['json']['session']['used_mfa']);
        $this->assertTrue($ohne['json']['session']['mfa_enrollment_required']);

        $mit = $this->as(self::TARGET, 'GET', '/api/me', usedMfa: true);
        $this->assertFalse($mit['json']['session']['mfa_enrollment_required']);
    }

    public function testKontoOhnePflichtrolleBrauchtKeinMfa(): void
    {
        $this->assign('erziehungsberechtigte');

        $this->assertSame(0, (int) $this->row(self::TARGET)['mfa_required']);
        $this->assertSame(200, $this->fachlicheRoute()['status']);
        $this->assertFalse($this->as(self::TARGET, 'GET', '/api/me')['json']['session']['mfa_enrollment_required']);
    }

    public function testLehrkraftUndElternrolleUnterliegtDerPflichtAuchAlsElternteil(): void
    {
        $this->assign('erziehungsberechtigte');
        $this->assign('lehrkraft');

        // Die Pflicht haengt am Konto, nicht an der Route oder am Portal: auch Elternfunktionen sind ohne MFA gesperrt.
        $this->assertSame(1, (int) $this->row(self::TARGET)['mfa_required']);
        $this->assertSame('mfa_enrollment_required', $this->fachlicheRoute()['json']['code']);
    }

    public function testWegfallDerLetztenPflichtrolleHebtDiePflichtAuf(): void
    {
        $this->assign('erziehungsberechtigte');
        $this->assign('lehrkraft');
        $this->assign('sekretariat');

        $this->revoke('lehrkraft');
        $this->assertSame('mfa_enrollment_required', $this->fachlicheRoute()['json']['code'], 'Sekretariat erfordert weiter MFA');

        $this->revoke('sekretariat');
        $this->assertSame(0, (int) $this->row(self::TARGET)['mfa_required']);
        $this->assertSame(200, $this->fachlicheRoute()['status']);
        // Ein eingerichtetes TOTP bleibt: die API setzt es nicht zurueck, sie hebt nur die Pflicht auf.
        $this->assertSame([], $this->stub->callsTo('/internal/users/mfa-reset'));
    }

    public function testGesperrtesKontoBleibt401NichtMfa(): void
    {
        $this->assign('lehrkraft');
        $this->app->setAccountStatus(self::TARGET, 'inactive');

        $r = $this->fachlicheRoute();
        $this->assertSame(401, $r['status']);
        $this->assertSame('account_inactive', $r['json']['code']);
    }

    // ---- Spiegeln an den Anmeldedienst (G2) ---------------------------------------------------

    public function testVergabeEinerPflichtrolleWirdGespiegelt(): void
    {
        $this->assign('lehrkraft');

        $calls = $this->stub->mfaRequirementCalls();
        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['signature_valid']);
        $this->assertSame(['event' => 'user.mfa_requirement', 'public_user_ids' => [self::TARGET], 'required' => true], $calls[0]['body']);
        $this->assertSame(0, (int) $this->row(self::TARGET)['mfa_sync_pending']);
    }

    public function testWegfallDerPflichtWirdAlsRequiredFalseGespiegelt(): void
    {
        $this->assign('lehrkraft');
        $this->revoke('lehrkraft');

        $calls = $this->stub->mfaRequirementCalls();
        $this->assertCount(2, $calls);
        $this->assertSame(['event' => 'user.mfa_requirement', 'public_user_ids' => [self::TARGET], 'required' => false], $calls[1]['body']);
    }

    public function testOhneAenderungDerPflichtKeinAufruf(): void
    {
        $this->assign('lehrkraft');
        $this->assign('sekretariat');
        $this->assign('erziehungsberechtigte');
        $this->revoke('erziehungsberechtigte');

        $this->assertCount(1, $this->stub->mfaRequirementCalls());
    }

    public function testMerkmalAenderungEinerEigenenRolleWirdFuerAlleKontenGespiegelt(): void
    {
        $created = $this->as(self::ADMIN, 'POST', '/api/roles', json: ['name' => 'Testrolle MFA', 'requires_mfa' => false]);
        $this->assertSame(201, $created['status']);
        $roleId = $created['json']['role']['id'];
        try {
            $this->app->createAccount(self::OTHER, 'active');
            foreach ([self::TARGET, self::OTHER] as $id) {
                $this->assertSame(200, $this->as(self::ADMIN, 'POST', "/api/accounts/$id/roles", json: ['role_id' => $roleId])['status']);
            }
            $this->assertSame([], $this->stub->mfaRequirementCalls(), 'Rolle ohne MFA: keine Pflicht, nichts zu spiegeln');

            $this->assertSame(200, $this->as(self::ADMIN, 'POST', "/api/roles/$roleId/update", json: ['requires_mfa' => true])['status']);

            $calls = $this->stub->mfaRequirementCalls();
            $this->assertCount(1, $calls);
            $this->assertEqualsCanonicalizing([self::TARGET, self::OTHER], $calls[0]['body']['public_user_ids']);
            $this->assertTrue($calls[0]['body']['required']);
        } finally {
            $this->app->pdo()->prepare('DELETE FROM `permission_user_roles` WHERE `role_id` = ?')->execute([$roleId]);
            $this->as(self::ADMIN, 'POST', "/api/roles/$roleId/delete");
        }
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function ausfaelle(): array
    {
        return [
            'Zeitueberschreitung' => [['delay' => 2.0]],
            '5xx' => [['status' => 503, 'body' => ['error' => 'down']]],
            'unlesbare Antwort' => [['status' => 200, 'raw' => '<html>kaputt</html>']],
            'fachliche Ablehnung' => [['status' => 400, 'body' => ['error' => 'x', 'code' => 'invalid_request']]],
        ];
    }

    /** @param array<string,mixed> $failure */
    #[DataProvider('ausfaelle')]
    public function testFehlerBeimSpiegelnLaesstDieLokaleDurchsetzungBestehen(array $failure): void
    {
        $this->stub->script('mfa-requirement', $failure);

        $r = $this->assign('lehrkraft');

        $this->assertSame(200, $r['status'], 'die Rollenvergabe gilt trotzdem');
        $this->assertSame(1, (int) $this->row(self::TARGET)['mfa_required']);
        $this->assertSame(1, (int) $this->row(self::TARGET)['mfa_sync_pending']);
        $this->assertSame('mfa_enrollment_required', $this->fachlicheRoute()['json']['code']);
    }

    public function testOffeneSpiegelungWirdBeimNaechstenAbgleichNachgeholt(): void
    {
        $this->stub->script('mfa-requirement', ['status' => 503, 'body' => ['error' => 'down']]);
        $this->assign('lehrkraft');
        $this->app->createAccount(self::OTHER, 'active');

        $this->assign('sekretariat', self::OTHER);

        $calls = $this->stub->mfaRequirementCalls();
        $this->assertCount(2, $calls);
        $this->assertSame([self::TARGET, self::OTHER], $calls[1]['body']['public_user_ids']);
        $this->assertSame(0, (int) $this->row(self::TARGET)['mfa_sync_pending']);
        $this->assertSame(0, (int) $this->row(self::OTHER)['mfa_sync_pending']);
    }

    // ---- Migration ----------------------------------------------------------------------------

    public function testMigrationMarkiertBestehendePflichtkontenEinmalAlsOffen(): void
    {
        $this->app->createAccount(self::OTHER, 'active');
        $pdo = $this->app->pdo();
        $pdo->exec('ALTER TABLE `accounts` DROP COLUMN `mfa_sync_pending`');
        $pdo->prepare('UPDATE `accounts` SET `mfa_required` = 1 WHERE `public_user_id` = ?')->execute([self::TARGET]);

        $this->app->rerunMigrations('0012_account_mfa_sync_pending.php');
        $this->assertSame(1, (int) $this->row(self::TARGET)['mfa_sync_pending']);
        $this->assertSame(0, (int) $this->row(self::OTHER)['mfa_sync_pending']);

        $pdo->exec('UPDATE `accounts` SET `mfa_sync_pending` = 0');
        $this->app->rerunMigrations('0012_account_mfa_sync_pending.php');
        $this->assertSame(0, (int) $this->row(self::TARGET)['mfa_sync_pending'], 'Wiederholung markiert nicht erneut');
    }
}
