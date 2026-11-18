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
use TeacherOS\Modules\Roles\RolesController;
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
        $authenticated = [[AuthenticationMiddleware::class, 'handle']];
        $router->get('/api/me', [self::class, 'me'], $authenticated);

        $router->get('/api/accounts', [AccountsController::class, 'index'], [...$authenticated, RequirePermission::for(PermissionCatalog::ACCOUNTS_VIEW)]);
        $router->post('/api/accounts/invitations', [AccountsController::class, 'invite'], [...$authenticated, RequirePermission::for(PermissionCatalog::ACCOUNTS_INVITE)]);
        $router->post('/api/accounts/:id/reinvite', [AccountsController::class, 'reinvite'], [...$authenticated, RequirePermission::for(PermissionCatalog::ACCOUNTS_INVITE)]);

        $reader = [...$authenticated, RequirePermission::forAny(PermissionCatalog::ADMINISTRATION)];
        $router->get('/api/permissions', [RolesController::class, 'permissions'], $reader);
        $router->get('/api/roles', [RolesController::class, 'index'], $reader);

        // Rollen an Konten (Ticket #34): Lesesicht der Zuweisungen, Vergeben und Entziehen einzeln.
        $router->get('/api/accounts/:id/access', [AccountAccessController::class, 'show'], $reader);
        $assigner = [...$authenticated, RequirePermission::for(PermissionCatalog::ROLES_ASSIGN)];
        $router->post('/api/accounts/:id/roles', [AccountAccessController::class, 'assign'], $assigner);
        $router->post('/api/accounts/:id/roles/:roleId/revoke', [AccountAccessController::class, 'revoke'], $assigner);

        // Rollen anlegen, bearbeiten, loeschen (Ticket #33). Nur GET/POST, weil der CORS-Standard nur diese zulaesst.
        $manager = [...$authenticated, RequirePermission::for(PermissionCatalog::ROLES_MANAGE)];
        $router->post('/api/roles', [RolesController::class, 'create'], $manager);
        $router->post('/api/roles/:id/update', [RolesController::class, 'update'], $manager);
        $router->post('/api/roles/:id/delete', [RolesController::class, 'delete'], $manager);

        // Einzelrechte (Ticket #36): gewaehren, entziehen, zuruecksetzen, je Recht einzeln.
        $permissionAssigner = [...$authenticated, RequirePermission::for(PermissionCatalog::PERMISSIONS_ASSIGN)];
        $router->post('/api/accounts/:id/permissions/grant', [AccountAccessController::class, 'grantPermission'], $permissionAssigner);
        $router->post('/api/accounts/:id/permissions/withdraw', [AccountAccessController::class, 'withdrawPermission'], $permissionAssigner);
        $router->post('/api/accounts/:id/permissions/clear', [AccountAccessController::class, 'clearPermission'], $permissionAssigner);
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
            'session' => [
                'used_mfa' => $session->token->usedMfa,
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
