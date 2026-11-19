<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

/**
 * Gewuenschte Aenderung an einer Rolle; `null` bei einem Feld heisst "unveraendert".
 * Die Beschreibung darf selbst `null` sein (leeren), deshalb das eigene Kennzeichen.
 */
final class RoleChanges
{
    /** @param list<string>|null $permissions */
    public function __construct(
        public readonly ?string $name = null,
        public readonly bool $changesDescription = false,
        public readonly ?string $description = null,
        public readonly ?array $permissions = null,
        public readonly ?bool $requiresMfa = null,
    ) {
    }
}
