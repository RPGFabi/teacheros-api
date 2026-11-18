<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Roles;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Security\AccessAdministration;
use TeacherOS\Shared\Security\AuthenticationMiddleware;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RoleOverview;

/**
 * Endpunkte der Rollenverwaltung: Lesen (Ticket #32), Anlegen, Bearbeiten, Loeschen (Ticket #33). Zugriff mit einem der drei Verwaltungsrechte
 * ({@see PermissionCatalog::ADMINISTRATION}), das prueft die Route (Schreibwege: `roles.manage`); Fehler uebersetzt der ApiRouter.
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

    public static function create(Request $request, Response $response): void
    {
        $input = RoleInput::forCreate($request->getBody());
        $id = (new AccessAdministration())->createRole(self::actor(), $input['name'], $input['description'], $input['permissions'], $input['requires_mfa']);
        $response->json(['role' => (new RoleOverview())->find($id)], 201);
    }

    public static function update(Request $request, Response $response): void
    {
        $id = (string) $request->getParam('id');
        (new AccessAdministration())->updateRole(self::actor(), $id, RoleInput::forUpdate($request->getBody()));
        $response->json(['role' => (new RoleOverview())->find($id)]);
    }

    public static function delete(Request $request, Response $response): void
    {
        $id = (string) $request->getParam('id');
        (new AccessAdministration())->deleteRole(self::actor(), $id);
        $response->json(['deleted' => true, 'id' => $id]);
    }

    private static function actor(): Actor
    {
        return Actor::account(AuthenticationMiddleware::current()->account->publicUserId);
    }
}
