<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Permissions\UserPermissionService;

/**
 * Rechte-Katalog: alle der Anwendung bekannten Rechte-Schluessel. Jedes Modul
 * traegt hier spaeter seine eigenen Schluessel ein. Die Wildcard `*` der
 * System-Rolle Schuladmin wird gegen diesen Katalog aufgeloest und verlaesst
 * die API nie.
 */
final class PermissionCatalog
{
    public const ACCOUNTS_VIEW = 'accounts.view';
    public const ACCOUNTS_INVITE = 'accounts.invite';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ACCOUNTS_VIEW, self::ACCOUNTS_INVITE];
    }

    public static function service(): UserPermissionService
    {
        return new UserPermissionService(self::all());
    }

    /**
     * Effektive Rechte eines Kontos: bei Wildcard der ganze Katalog, sonst die
     * Rechte aus Rollen und Einzelrechten, gefiltert auf den Katalog. Nie `*`.
     *
     * @return list<string>
     */
    public static function effectiveFor(string $publicUserId): array
    {
        return array_values(self::service()->getPermissionsForUser($publicUserId));
    }
}
