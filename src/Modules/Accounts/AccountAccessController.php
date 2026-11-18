<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Accounts;

use Hoelzle\Core\Routing\Request;
use Hoelzle\Core\Routing\Response;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AccessAdministration;
use TeacherOS\Shared\Security\AccountAccessView;
use TeacherOS\Shared\Security\AuthenticationMiddleware;

/**
 * Zuweisungen eines Kontos (Ticket #34): Kontodetail lesen (`accounts.view` und eines der drei Verwaltungsrechte),
 * Rollen einzeln vergeben und entziehen (`roles.assign`), Einzelrechte gewaehren, entziehen und zuruecksetzen
 * (`permissions.assign`); das prueft jeweils die Route. Fehler mit Code uebersetzt der ApiRouter.
 * Alle Aenderungen laufen ueber {@see AccessAdministration}; jede Antwort ist die aktuelle Lesesicht.
 */
final class AccountAccessController
{
    public static function show(Request $request, Response $response): void
    {
        $response->json(self::view((string) $request->getParam('id')));
    }

    public static function assign(Request $request, Response $response): void
    {
        $roleId = $request->getBody('role_id');
        if (!is_string($roleId) || trim($roleId) === '') {
            throw new CodedException('role_required', 400, 'Bitte eine Rolle angeben.');
        }
        self::change($request, $response, fn (Actor $actor, string $accountId) => (new AccessAdministration())->assignRole($actor, $accountId, trim($roleId)));
    }

    public static function revoke(Request $request, Response $response): void
    {
        $roleId = (string) $request->getParam('roleId');
        self::change($request, $response, fn (Actor $actor, string $accountId) => (new AccessAdministration())->revokeRole($actor, $accountId, $roleId));
    }

    /** Einzelrecht gewaehren (Ticket #36). */
    public static function grantPermission(Request $request, Response $response): void
    {
        $permission = self::permissionFrom($request);
        self::change($request, $response, fn (Actor $actor, string $accountId) => (new AccessAdministration())->grantPermission($actor, $accountId, $permission));
    }

    /** Einzelrecht entziehen, trotz Rolle (Ticket #36). */
    public static function withdrawPermission(Request $request, Response $response): void
    {
        $permission = self::permissionFrom($request);
        self::change($request, $response, fn (Actor $actor, string $accountId) => (new AccessAdministration())->withdrawPermission($actor, $accountId, $permission));
    }

    /** Einzelrecht zuruecksetzen (Ticket #36): es gilt wieder, was die Rollen ergeben. */
    public static function clearPermission(Request $request, Response $response): void
    {
        $permission = self::permissionFrom($request);
        self::change($request, $response, fn (Actor $actor, string $accountId) => (new AccessAdministration())->clearPermissionOverride($actor, $accountId, $permission));
    }

    /**
     * Konto deaktivieren (Ticket #41). Antwort: die aktuelle Lesesicht. Nach dem lokalen Commit wird die Sperre an den
     * Anmeldedienst gespiegelt (Ticket #42); scheitert das, bleibt sie bestehen, das Konto ist 200 deaktiviert und die
     * Lesesicht nennt `login_sync_pending` ("Abgleich offen").
     */
    public static function deactivate(Request $request, Response $response): void
    {
        self::change($request, $response, function (Actor $actor, string $accountId): void {
            (new AccessAdministration())->deactivateAccount($actor, $accountId);
            (new AccountLoginSync())->mirrorDeactivation($accountId);
        });
    }

    /**
     * "Abgleich wiederholen" (Ticket #42): ruft `disable-login` erneut auf. Kein Audit-Eintrag. Fehler: 409 `sync_not_pending`,
     * 502 `auth_unavailable` (die Markierung bleibt). Antwort: die aktuelle Lesesicht.
     */
    public static function resyncLogin(Request $request, Response $response): void
    {
        self::change($request, $response, fn (Actor $actor, string $accountId) => (new AccountLoginSync())->resync($accountId));
    }

    /** Fuehrt eine Aenderung am Konto der Route aus und antwortet mit der aktuellen Lesesicht. @param callable(Actor, string): void $change */
    private static function change(Request $request, Response $response, callable $change): void
    {
        $accountId = (string) $request->getParam('id');
        $change(AuthenticationMiddleware::actor(), $accountId);
        $response->json(self::view($accountId));
    }

    private static function permissionFrom(Request $request): string
    {
        $permission = $request->getBody('permission');
        if (!is_string($permission) || trim($permission) === '') {
            throw new CodedException('permission_required', 400, 'Bitte ein Recht angeben.');
        }
        return trim($permission);
    }

    /** @return array<string,mixed> */
    private static function view(string $accountId): array
    {
        return (new AccountAccessView())->for($accountId, AuthenticationMiddleware::current()->account->publicUserId);
    }
}
