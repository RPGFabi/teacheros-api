<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolSettings;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Endpunkte der Schuleinstellungen (Ticket #66). Modulpruefung und Recht (`school_settings.manage`) stehen an der Route
 * ({@see SchoolSettingRoutes}); Fehler mit Code uebersetzt der ApiRouter.
 */
final class SchoolSettingsController
{
    public function __construct(private readonly SchoolSettings $settings = new SchoolSettings())
    {
    }

    public function index(Request $request, Response $response): void
    {
        $response->json(['school_settings' => $this->settings->list()]);
    }

    public function update(Request $request, Response $response): void
    {
        $body = $request->getBody();
        if (!is_array($body)) {
            throw new CodedException('invalid_request', 400, 'Bitte mindestens eine Schuleinstellung angeben.');
        }
        $response->json(['school_settings' => $this->settings->update(AuthenticationMiddleware::actor(), $body)]);
    }
}
