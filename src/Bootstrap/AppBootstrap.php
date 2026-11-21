<?php

declare(strict_types=1);

namespace TeacherOS\Bootstrap;

use Hoelzle\Core\Application;
use Hoelzle\Core\Config\Config;
use Hoelzle\Core\Database\DB;
use Hoelzle\Core\Debug\Debugger;
use Hoelzle\Core\Http\CorsHandler;
use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Modules\Accounts\AccountAccessController;
use TeacherOS\Modules\Accounts\AccountsController;
use TeacherOS\Modules\Accounts\OwnAccountController;
use TeacherOS\Modules\Classes\ClassesModule;
use TeacherOS\Modules\Subjects\SubjectsModule;
use TeacherOS\Modules\Roles\RolesController;
use TeacherOS\Modules\SchoolYears\SchoolYearsModule;
use TeacherOS\Shared\Extension\ExtensionPoints;
use TeacherOS\Shared\Modules\ModuleEntitlements;
use TeacherOS\Shared\Security\AuthenticationMiddleware;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RequirePermission;

final class AppBootstrap
{
    public static function run(): void
    {
        ob_start();
        Application::initialize(dirname(__DIR__, 2));
        Debugger::initRequest();
        Config::load();

        header('Content-Type: application/json');

        if (!CorsHandler::fromEnv()->handle()) {
            return;
        }

        try {
            $router = new ApiRouter();
            self::registerRoutes($router);
            $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
        } catch (\Throwable $e) {
            Debugger::error($e->getMessage(), 'BOOTSTRAP');
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error', 'request_id' => Debugger::getRequestId()]);
        }
    }

    private static function registerRoutes(ApiRouter $router): void
    {
        $router->get('/api/health', [self::class, 'health']);
        // Jede fachliche Route setzt die MFA-Pflicht durch (B3); nur /api/me bleibt ohne MFA erreichbar, damit das Web
        // die Einrichtung anstossen kann.
        $authenticated = [[AuthenticationMiddleware::class, 'handle']];
        $router->get('/api/me', [self::class, 'me'], [[AuthenticationMiddleware::class, 'handleAllowingMfaEnrollment']]);
        // Eigener Sitzungswiderruf auf "Mein Konto" (Ticket #50): ohne besonderes Recht, jedes Konto beendet nur seine eigenen Sitzungen.
        $router->post('/api/me/revoke-sessions', [OwnAccountController::class, 'revokeSessions'], $authenticated);

        $router->get('/api/accounts', [AccountsController::class, 'index'], [...$authenticated, RequirePermission::for(PermissionCatalog::ACCOUNTS_VIEW)]);
        $router->post('/api/accounts/invitations', [AccountsController::class, 'invite'], [...$authenticated, RequirePermission::for(PermissionCatalog::ACCOUNTS_INVITE)]);
        $router->post('/api/accounts/:id/reinvite', [AccountsController::class, 'reinvite'], [...$authenticated, RequirePermission::for(PermissionCatalog::ACCOUNTS_INVITE)]);

        $reader = [...$authenticated, RequirePermission::forAny(PermissionCatalog::ADMINISTRATION)];
        $router->get('/api/permissions', [RolesController::class, 'permissions'], $reader);
        $router->get('/api/roles', [RolesController::class, 'index'], $reader);

        // Rollen an Konten (Ticket #34): Lesesicht der Zuweisungen, Vergeben und Entziehen einzeln.
        // Kontodetail: accounts.view und eines der drei Verwaltungsrechte oder accounts.deactivate (ADR 0002 B4, Review #12).
        $router->get('/api/accounts/:id/access', [AccountAccessController::class, 'show'], [...$authenticated, RequirePermission::for(PermissionCatalog::ACCOUNTS_VIEW), RequirePermission::forAny(PermissionCatalog::ACCOUNT_DETAIL_READERS)]);
        $assigner = [...$authenticated, RequirePermission::for(PermissionCatalog::ROLES_ASSIGN)];
        $router->post('/api/accounts/:id/roles', [AccountAccessController::class, 'assign'], $assigner);
        $router->post('/api/accounts/:id/roles/:roleId/revoke', [AccountAccessController::class, 'revoke'], $assigner);

        // Rollen anlegen, bearbeiten, loeschen (Ticket #33). Nur GET/POST, weil der CORS-Standard nur diese zulaesst.
        $manager = [...$authenticated, RequirePermission::for(PermissionCatalog::ROLES_MANAGE)];
        $router->post('/api/roles', [RolesController::class, 'create'], $manager);
        $router->post('/api/roles/:id/update', [RolesController::class, 'update'], $manager);
        $router->post('/api/roles/:id/delete', [RolesController::class, 'delete'], $manager);

        // Konto deaktivieren (Ticket #41) und den Abgleich mit dem Anmeldedienst wiederholen (Ticket #42). Reaktivieren (Ticket #43).
        // Die Antworten sind die Lesesicht des Kontos: darum zusaetzlich accounts.view (Review #12).
        $deactivator = [...$authenticated, RequirePermission::for(PermissionCatalog::ACCOUNTS_DEACTIVATE), RequirePermission::for(PermissionCatalog::ACCOUNTS_VIEW)];
        $router->post('/api/accounts/:id/deactivate', [AccountAccessController::class, 'deactivate'], $deactivator);
        // "Abgleich wiederholen" (Ticket #47): eines der Konto-Rechte genuegt, wie bei den Aufrufen, die es nachholt.
        $resyncer = [...$authenticated, RequirePermission::forAny(PermissionCatalog::ACCOUNT_ACTION_RIGHTS), RequirePermission::for(PermissionCatalog::ACCOUNTS_VIEW)];
        $router->post('/api/accounts/:id/resync-login', [AccountAccessController::class, 'resyncLogin'], $resyncer);
        $router->post('/api/accounts/:id/reactivate', [AccountAccessController::class, 'reactivate'], $deactivator);
        // Sitzungswiderruf durch die Verwaltung (Ticket #48): eigenes Recht, dazu accounts.view wegen der Lesesicht als Antwort.
        $router->post('/api/accounts/:id/revoke-sessions', [AccountAccessController::class, 'revokeSessions'], [...$authenticated, RequirePermission::for(PermissionCatalog::ACCOUNTS_REVOKE_SESSIONS), RequirePermission::for(PermissionCatalog::ACCOUNTS_VIEW)]);
        // TOTP-Zuruecksetzung durch die Verwaltung (Ticket #49): eigenes Recht, dazu accounts.view wegen der Lesesicht als Antwort.
        $router->post('/api/accounts/:id/reset-totp', [AccountAccessController::class, 'resetTotp'], [...$authenticated, RequirePermission::for(PermissionCatalog::ACCOUNTS_RESET_MFA), RequirePermission::for(PermissionCatalog::ACCOUNTS_VIEW)]);

        // Einzelrechte (Ticket #36): gewaehren, entziehen, zuruecksetzen, je Recht einzeln.
        $permissionAssigner = [...$authenticated, RequirePermission::for(PermissionCatalog::PERMISSIONS_ASSIGN)];
        $router->post('/api/accounts/:id/permissions/grant', [AccountAccessController::class, 'grantPermission'], $permissionAssigner);
        $router->post('/api/accounts/:id/permissions/withdraw', [AccountAccessController::class, 'withdrawPermission'], $permissionAssigner);
        $router->post('/api/accounts/:id/permissions/clear', [AccountAccessController::class, 'clearPermission'], $permissionAssigner);

        // Alle bisherigen Routen gehoeren zu `core` (immer aktiv). Routen eines Fachmoduls bauen ihre Middleware mit
        // ModuleGate::route(): Anmeldung, dann Modulfreigabe, dann Rechte (ADR 0002 B5, Ticket #13).
        // Stammdaten (Spec #17, ebenfalls `core`): jedes Modul meldet seine Routen und seine Pruefungen an den Erweiterungspunkten
        // anderer Module selbst an (ModuleRegistration); hier steht nur die Liste. Neue Module kommen in diese Liste.
        $points = new ExtensionPoints();
        foreach ([new SchoolYearsModule(), new ClassesModule(), new SubjectsModule()] as $module) {
            $module->register($router, $points);
        }

        self::registerTestRoutes($router, $points);
    }

    /**
     * Nur in der Testumgebung: zusaetzliche Routen aus der Datei in TEST_ROUTES (z. B. eine Probe-Route eines
     * Fachmoduls, solange es noch keine echten Fachrouten gibt). Ausserhalb von APP_ENV=test wirkungslos.
     */
    private static function registerTestRoutes(ApiRouter $router, ExtensionPoints $points): void
    {
        if (Config::getString('APP_ENV') !== 'test') {
            return;
        }
        $file = Config::getString('TEST_ROUTES');
        if ($file !== '' && is_file($file)) {
            (require $file)($router, $points);
        }
    }

    public static function me(Request $request, Response $response): void
    {
        $session = AuthenticationMiddleware::current();
        $response->json([
            'account' => [
                'id' => $session->account->publicUserId,
                'status' => $session->account->status->value,
                'mfa_required' => $session->account->mfaRequired,
            ],
            'permissions' => PermissionCatalog::effectiveFor($session->account->publicUserId),
            // Aktive Module der Schulinstanz (immer mit `core`); das Web blendet danach die Navigation aus (Ticket #13).
            'modules' => (new ModuleEntitlements())->active(),
            'session' => [
                'used_mfa' => $session->token->usedMfa,
                // B3: ohne zweiten Faktor sind fachliche Routen gesperrt (403 mfa_enrollment_required), bis TOTP eingerichtet und neu angemeldet ist.
                'mfa_enrollment_required' => $session->account->mfaRequired && !$session->token->usedMfa,
                'expires_at' => gmdate('c', $session->token->expiresAt),
            ],
        ]);
    }

    public static function health(Request $request, Response $response): void
    {
        $databaseOk = self::databaseReachable();
        $response->json([
            'status' => $databaseOk ? 'ok' : 'error',
            'service' => 'teacheros-api',
            'timestamp' => gmdate('c'),
            'database' => $databaseOk ? 'ok' : 'unavailable',
        ], $databaseOk ? 200 : 503);
    }

    private static function databaseReachable(): bool
    {
        try {
            DB::reset();
            return (int) DB::getInstance()->query('SELECT COUNT(*) FROM `app_meta`')->fetchColumn() >= 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
