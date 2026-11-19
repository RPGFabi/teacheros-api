<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

use Hoelzle\Permissions\UserPermissionService;

/**
 * Rechte-Katalog: alle der Anwendung bekannten Rechte mit deutschem Namen und Gruppe.
 * Jedes Modul traegt hier spaeter seine eigenen Eintraege ein; ein Recht ohne Eintrag gibt es nicht.
 * Die Wildcard `*` der System-Rolle Schuladmin wird gegen diesen Katalog aufgeloest und verlaesst
 * die API nie.
 */
final class PermissionCatalog
{
    public const ACCOUNTS_VIEW = 'accounts.view';
    public const ACCOUNTS_INVITE = 'accounts.invite';
    public const ROLES_MANAGE = 'roles.manage';
    public const ROLES_ASSIGN = 'roles.assign';
    public const PERMISSIONS_ASSIGN = 'permissions.assign';

    /**
     * Die drei Verwaltungsrechte (ADR 0002 B4). Wer eines hat, darf Rollen, Rechte-Katalog und
     * Zuweisungen lesen; ein eigenes `roles.view` gibt es nicht.
     */
    public const ADMINISTRATION = [self::ROLES_MANAGE, self::ROLES_ASSIGN, self::PERMISSIONS_ASSIGN];

    private const GROUP_ACCOUNTS = 'Konten';
    private const GROUP_ROLES = 'Rollen und Rechte';

    /**
     * Katalogreihenfolge = Anzeigereihenfolge.
     *
     * @return list<array{key:string,name:string,group:string}>
     */
    public static function entries(): array
    {
        return [
            ['key' => self::ACCOUNTS_VIEW, 'name' => 'Konten ansehen', 'group' => self::GROUP_ACCOUNTS],
            ['key' => self::ACCOUNTS_INVITE, 'name' => 'Konten einladen', 'group' => self::GROUP_ACCOUNTS],
            ['key' => self::ROLES_MANAGE, 'name' => 'Rollen verwalten', 'group' => self::GROUP_ROLES],
            ['key' => self::ROLES_ASSIGN, 'name' => 'Rollen vergeben und entziehen', 'group' => self::GROUP_ROLES],
            ['key' => self::PERMISSIONS_ASSIGN, 'name' => 'Einzelrechte vergeben und entziehen', 'group' => self::GROUP_ROLES],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_column(self::entries(), 'key');
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
