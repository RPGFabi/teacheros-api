<?php

declare(strict_types=1);

namespace TeacherOS\Shared\AuthService;

/**
 * Antwort des Anmeldedienstes auf `POST /internal/users/invite`.
 * `username` und `inviteCode` liefert der Dienst erst ab G6 (Einladung per Anmeldename ohne E-Mail).
 */
final class InviteResult
{
    public function __construct(
        public readonly string $publicUserId,
        public readonly bool $created,
        public readonly bool $alreadyActive,
        public readonly ?string $username = null,
        public readonly ?string $inviteCode = null,
    ) {
    }
}
