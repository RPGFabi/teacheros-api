<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Accounts;

/** Ergebnis einer Einladung. `inviteCode` gibt es nur, wenn der Anmeldedienst ihn liefert (ab G6, ohne E-Mail); er wird nirgends gespeichert. */
final class InvitationResult
{
    public function __construct(
        public readonly string $publicUserId,
        public readonly ?string $email,
        public readonly string $loginName,
        public readonly bool $reinvited,
        public readonly ?string $inviteCode = null,
    ) {
    }
}