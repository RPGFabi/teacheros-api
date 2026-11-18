<?php

declare(strict_types=1);

namespace TeacherOS\Shared\AuthService;

/**
 * Antwort des Anmeldedienstes auf `POST /internal/users/invite` (nicht zu verwechseln mit dem
 * Ergebnis der Einladung im Modul Konten, `InvitationResult`).
 * `username`, `inviteCode` und `expiresAt` liefert der Dienst erst ab G6 (Einladung per Anmeldename ohne E-Mail);
 * `expiresAt` (ISO 8601) fehlt bei einem schon aktiven Konto.
 */
final class InviteAnswer
{
    public function __construct(
        public readonly string $publicUserId,
        public readonly bool $created,
        public readonly bool $alreadyActive,
        public readonly ?string $username = null,
        public readonly ?string $inviteCode = null,
        public readonly ?string $expiresAt = null,
    ) {
    }
}
