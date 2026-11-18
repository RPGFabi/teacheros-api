<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Accounts;

/**
 * Ergebnis einer Einladung im Modul Konten (nicht zu verwechseln mit der Antwort des Anmeldedienstes,
 * `InviteAnswer`). Das Konto ist danach immer `eingeladen`; `invitedAt` ist "Eingeladen am" (ISO 8601, UTC).
 * `inviteCode` gibt es nur, wenn der Anmeldedienst ihn liefert (ab G6, ohne E-Mail), `inviteExpiresAt` (ISO 8601),
 * wenn er das Ablaufdatum nennt. Beides wird nirgends gespeichert.
 */
final class InvitationResult
{
    public function __construct(
        public readonly string $publicUserId,
        public readonly ?string $email,
        public readonly string $loginName,
        public readonly bool $reinvited,
        public readonly ?string $invitedAt,
        public readonly ?string $inviteCode = null,
        public readonly ?string $inviteExpiresAt = null,
    ) {
    }
}
