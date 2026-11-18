<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Roles;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RoleOverview;

/**
 * Leseendpunkte der Rollenverwaltung (Ticket #32). Zugriff mit einem der drei Verwaltungsrechte
 * ({@see PermissionCatalog::ADMINISTRATION}), das prueft die Route; Fehler uebersetzt der ApiRouter.
 */
final class RolesController
{
    public static function permissions(Request $request, Response $response): void
    {
        $response->json(['permissions' => PermissionCatalog::entries()]);
    }

    public static function index(Request $request, Response $response): void
    {
        $response->json(['roles' => (new RoleOverview())->list()]);
    }
}
