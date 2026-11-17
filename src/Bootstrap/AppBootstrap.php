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
use Hoelzle\Core\Routing\Router;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

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
            $router = new Router();
            self::registerRoutes($router);
            $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
        } catch (\Throwable $e) {
            Debugger::error($e->getMessage(), 'BOOTSTRAP');
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error', 'request_id' => Debugger::getRequestId()]);
        }
    }

    private static function registerRoutes(Router $router): void
    {
        $router->get('/api/health', [self::class, 'health']);
        $router->get('/api/me', [self::class, 'me'], [[AuthenticationMiddleware::class, 'handle']]);
    }

    public static function me(Request $request, Response $response): void
    {
        $session = AuthenticationMiddleware::current();
        $response->json([
            'account' => [
                'id' => $session->account->publicUserId,
                'status' => $session->account->status,
                'mfa_required' => $session->account->mfaRequired,
            ],
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
