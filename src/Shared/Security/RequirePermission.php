<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;

/** Route-Middleware: verlangt ein Recht (nicht eine Rolle). Nach {@see AuthenticationMiddleware::handle}. */
final class RequirePermission
{
    public static function for(string $permission): \Closure
    {
        return static function (Request $request, Response $response) use ($permission): bool {
            $account = AuthenticationMiddleware::current()->account;
            if (!PermissionCatalog::service()->can($account->publicUserId, $permission)) {
                $response->json(['error' => 'Missing permission', 'code' => 'forbidden'], 403);
                return false;
            }
            return true;
        };
    }
}
