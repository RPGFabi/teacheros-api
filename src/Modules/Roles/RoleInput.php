<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Roles;

use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\PermissionCatalog;
use TeacherOS\Shared\Security\RoleChanges;

/**
 * Prueft und normalisiert den Request-Body zum Anlegen und Bearbeiten von Rollen.
 * Jeder Fehler ist `400 invalid_request`; die fachlichen Regeln (Schutzstufen, P2) liegen in AccessAdministration.
 */
final class RoleInput
{
    private const NAME_MAX = 120;
    private const DESCRIPTION_MAX = 500;

    /**
     * @param mixed $body Request-Body
     * @return array{name:string,description:?string,permissions:list<string>,requires_mfa:bool}
     * @throws CodedException
     */
    public static function forCreate(mixed $body): array
    {
        $changes = self::forUpdate($body);
        if ($changes->name === null) {
            throw self::invalid('Bitte einen Namen angeben.');
        }
        return [
            'name' => $changes->name,
            'description' => $changes->description,
            'permissions' => $changes->permissions ?? [],
            'requires_mfa' => $changes->requiresMfa ?? true,
        ];
    }

    /** @throws CodedException */
    public static function forUpdate(mixed $body): RoleChanges
    {
        if (!is_array($body) || array_is_list($body) && $body !== []) {
            throw self::invalid('Der Request-Body muss ein JSON-Objekt sein.');
        }
        return new RoleChanges(
            name: array_key_exists('name', $body) ? self::name($body['name']) : null,
            changesDescription: array_key_exists('description', $body),
            description: self::description($body['description'] ?? null),
            permissions: array_key_exists('permissions', $body) ? self::permissions($body['permissions']) : null,
            requiresMfa: array_key_exists('requires_mfa', $body) ? self::bool($body['requires_mfa']) : null,
        );
    }

    private static function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw self::invalid('Bitte einen Namen mit höchstens ' . self::NAME_MAX . ' Zeichen angeben.');
        }
        return $name;
    }

    private static function description(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > self::DESCRIPTION_MAX) {
            throw self::invalid('Die Beschreibung darf höchstens ' . self::DESCRIPTION_MAX . ' Zeichen lang sein.');
        }
        return trim($value) === '' ? null : trim($value);
    }

    /** @return list<string> */
    private static function permissions(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid('Die Rechte müssen eine Liste von Schlüsseln sein.');
        }
        $known = PermissionCatalog::all();
        foreach ($value as $permission) {
            if (!is_string($permission) || !in_array($permission, $known, true)) {
                throw self::invalid('Unbekanntes Recht.');
            }
        }
        return array_values(array_unique($value));
    }

    private static function bool(mixed $value): bool
    {
        return is_bool($value) ? $value : throw self::invalid('„Erfordert MFA“ muss true oder false sein.');
    }

    private static function invalid(string $message): CodedException
    {
        return new CodedException('invalid_request', 400, $message);
    }
}
