<?php

declare(strict_types=1);

namespace TeacherOS\Bootstrap;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use Hoelzle\Core\Routing\Router;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Router, der fachliche Fehler mit Code als `{error, code}` beantwortet.
 *
 * Der Router aus php-core kennt nur `{error}`. Hier wird jeder Handler und jede Middleware beim
 * Registrieren so umhuellt, dass eine {@see CodedException} (z. B. `already_active`, `auth_unavailable`)
 * zentral in Status und Code uebersetzt wird; einzelne Endpunkte muessen nichts davon wissen.
 */
final class ApiRouter extends Router
{
    public function addRoute(string $method, string $path, callable $handler, array $middlewares = []): void
    {
        parent::addRoute(
            $method,
            $path,
            self::withCodedErrors($handler),
            array_map(self::withCodedErrors(...), $middlewares),
        );
    }

    private static function withCodedErrors(callable $inner): \Closure
    {
        return static function (Request $request, Response $response) use ($inner) {
            try {
                return $inner($request, $response);
            } catch (CodedException $e) {
                if ($e->getHttpCode() === 500) {
                    throw $e;
                }
                $response->json(['error' => $e->getMessage(), 'code' => $e->errorCode], $e->getHttpCode());
                return false;
            }
        };
    }
}
