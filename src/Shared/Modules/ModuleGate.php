<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Modules;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Security\AuthenticationMiddleware;
use TeacherOS\Shared\Security\RequirePermission;

/**
 * Route-Middleware: verlangt, dass das Modul an der Schulinstanz freigegeben ist (ADR 0002 B5). Steht nach
 * {@see AuthenticationMiddleware::handle} und vor jeder {@see RequirePermission}: ein inaktives Modul antwortet
 * `403 module_disabled`, egal welche Rechte das Konto hat. Eine Freigabe ersetzt umgekehrt kein Recht.
 *
 * Fachrouten werden ueber {@see self::route()} gebaut, damit die Reihenfolge nicht an jeder Route neu stimmen muss.
 */
final class ModuleGate
{
    public static function require(string $moduleId): \Closure
    {
        if (!ModuleCatalog::exists($moduleId)) {
            throw new \LogicException("Route verlangt ein Modul, das es nicht gibt: {$moduleId}");
        }
        return static function (Request $request, Response $response) use ($moduleId): bool {
            if (!(new ModuleEntitlements())->isActive($moduleId)) {
                $response->json(['error' => 'Module disabled', 'code' => 'module_disabled', 'module' => $moduleId], 403);
                return false;
            }
            return true;
        };
    }

    /**
     * Middleware-Kette einer Fachroute in der verbindlichen Reihenfolge: Anmeldung, Modulfreigabe, dann die Rechte.
     *
     * @param list<callable> $permissionChecks z. B. {@see RequirePermission::for()}
     * @return list<callable>
     */
    public static function route(string $moduleId, array $permissionChecks): array
    {
        return [[AuthenticationMiddleware::class, 'handle'], self::require($moduleId), ...$permissionChecks];
    }
}
