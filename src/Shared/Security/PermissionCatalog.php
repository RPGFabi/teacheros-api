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
    public const ACCOUNTS_DEACTIVATE = 'accounts.deactivate';
    public const ACCOUNTS_REVOKE_SESSIONS = 'accounts.revoke_sessions';
    public const ACCOUNTS_RESET_MFA = 'accounts.reset_mfa';
    public const SCHOOL_YEARS_VIEW = 'school_years.view';
    public const SCHOOL_YEARS_MANAGE = 'school_years.manage';
    public const CLASSES_VIEW = 'classes.view';
    public const CLASSES_MANAGE = 'classes.manage';
    public const SUBJECTS_VIEW = 'subjects.view';
    public const SUBJECTS_MANAGE = 'subjects.manage';
    public const SCHOOL_SETTINGS_MANAGE = 'school_settings.manage';
    public const TEACHING_GROUPS_VIEW = 'teaching_groups.view';
    public const TEACHING_GROUPS_MANAGE = 'teaching_groups.manage';
    public const TEACHER_ASSIGNMENTS_MANAGE = 'teacher_assignments.manage';

    /**
     * Die drei Verwaltungsrechte (ADR 0002 B4). Wer eines hat, darf Rollen, Rechte-Katalog und
     * Zuweisungen lesen; ein eigenes `roles.view` gibt es nicht.
     */
    public const ADMINISTRATION = [self::ROLES_MANAGE, self::ROLES_ASSIGN, self::PERMISSIONS_ASSIGN];

    /**
     * Die Rechte fuer Aktionen am Konto selbst, die lokal wirken und den Anmeldedienst unterrichten (ADR 0002 Nachtrag
     * #16, Ticket #47). Wer eines hat (und `accounts.view`), darf "Abgleich wiederholen", der alle offenen Aufrufe eines
     * Kontos nachholt. Der Widerruf (#48) hat `accounts.revoke_sessions`, die TOTP-Zuruecksetzung (#49) `accounts.reset_mfa`.
     */
    public const ACCOUNT_ACTION_RIGHTS = [self::ACCOUNTS_DEACTIVATE, self::ACCOUNTS_REVOKE_SESSIONS, self::ACCOUNTS_RESET_MFA];

    /**
     * Wer ein Kontodetail lesen darf, zusaetzlich zu `accounts.view` (ADR 0002 B4, Review #12): eines der drei
     * Verwaltungsrechte oder ein Konto-Recht (der Verwalter der Deaktivierung braucht die Lesesicht).
     */
    public const ACCOUNT_DETAIL_READERS = [...self::ADMINISTRATION, ...self::ACCOUNT_ACTION_RIGHTS];

    private const GROUP_ACCOUNTS = 'Konten';
    private const GROUP_ROLES = 'Rollen und Rechte';
    private const GROUP_MASTER_DATA = 'Stammdaten';

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
            ['key' => self::ACCOUNTS_DEACTIVATE, 'name' => 'Konten deaktivieren und reaktivieren', 'group' => self::GROUP_ACCOUNTS],
            ['key' => self::ACCOUNTS_REVOKE_SESSIONS, 'name' => 'Sitzungen von Konten beenden', 'group' => self::GROUP_ACCOUNTS],
            ['key' => self::ACCOUNTS_RESET_MFA, 'name' => 'TOTP von Konten zurücksetzen', 'group' => self::GROUP_ACCOUNTS],
            ['key' => self::ROLES_MANAGE, 'name' => 'Rollen verwalten', 'group' => self::GROUP_ROLES],
            ['key' => self::ROLES_ASSIGN, 'name' => 'Rollen vergeben und entziehen', 'group' => self::GROUP_ROLES],
            ['key' => self::PERMISSIONS_ASSIGN, 'name' => 'Einzelrechte vergeben und entziehen', 'group' => self::GROUP_ROLES],
            ['key' => self::SCHOOL_YEARS_VIEW, 'name' => 'Schuljahre ansehen', 'group' => self::GROUP_MASTER_DATA],
            ['key' => self::SCHOOL_YEARS_MANAGE, 'name' => 'Schuljahre verwalten', 'group' => self::GROUP_MASTER_DATA],
            ['key' => self::CLASSES_VIEW, 'name' => 'Klassen ansehen', 'group' => self::GROUP_MASTER_DATA],
            ['key' => self::CLASSES_MANAGE, 'name' => 'Klassen verwalten', 'group' => self::GROUP_MASTER_DATA],
            ['key' => self::SUBJECTS_VIEW, 'name' => 'Fächer ansehen', 'group' => self::GROUP_MASTER_DATA],
            ['key' => self::SUBJECTS_MANAGE, 'name' => 'Fächer verwalten', 'group' => self::GROUP_MASTER_DATA],
            ['key' => self::SCHOOL_SETTINGS_MANAGE, 'name' => 'Schuleinstellungen verwalten', 'group' => self::GROUP_MASTER_DATA],
            ['key' => self::TEACHING_GROUPS_VIEW, 'name' => 'Fachgruppen ansehen', 'group' => self::GROUP_MASTER_DATA],
            ['key' => self::TEACHING_GROUPS_MANAGE, 'name' => 'Fachgruppen verwalten', 'group' => self::GROUP_MASTER_DATA],
            ['key' => self::TEACHER_ASSIGNMENTS_MANAGE, 'name' => 'Lehrkraft-Zuordnungen verwalten', 'group' => self::GROUP_MASTER_DATA],
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
