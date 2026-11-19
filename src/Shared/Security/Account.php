<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

final class Account
{
    /**
     * @param int|null $sessionsValidAfter Unix-Zeit: Tokens mit `iat` bis einschliesslich dieser Zeit sind widerrufen (ADR 0002 B2); null = keine Grenze
     * @param int|null $inactiveSince Unix-Zeit der Deaktivierung
     * @param bool $loginSyncPending "Abgleich offen": die Deaktivierung ist dem Anmeldedienst noch nicht gemeldet (Ticket #42)
     * @param string|null $loginSyncError Code, mit dem der Anmeldedienst den Abgleich fachlich abgelehnt hat (z. B. `user_not_found`); null bei Nichterreichbarkeit oder ohne offenen Abgleich
     */
    public function __construct(
        public readonly string $publicUserId,
        public readonly AccountStatus $status,
        public readonly bool $mfaRequired,
        public readonly ?string $email = null,
        public readonly string $loginName = '',
        public readonly ?int $sessionsValidAfter = null,
        public readonly ?int $inactiveSince = null,
        public readonly ?InactiveReason $inactiveReason = null,
        public readonly bool $loginSyncPending = false,
        public readonly ?string $loginSyncError = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    /** "Abgleich offen": ein deaktiviertes Konto, dessen Deaktivierung dem Anmeldedienst noch nicht gemeldet ist (Ticket #42). */
    public function awaitsLoginSync(): bool
    {
        return $this->status === AccountStatus::Inactive && $this->loginSyncPending;
    }

    /**
     * Ist ein Token, das um $issuedAt ausgestellt wurde, durch die Sitzungsgrenze widerrufen? Ohne `iat` fail-closed.
     * Gleichheit widerruft (`iat <= Grenze`): `iat` und Grenze haben Sekundenaufloesung, ein Token aus der Sekunde
     * der Deaktivierung kann vor ihr entstanden sein (ADR 0002 B2).
     */
    public function revokes(?int $issuedAt): bool
    {
        return $this->sessionsValidAfter !== null && ($issuedAt === null || $issuedAt <= $this->sessionsValidAfter);
    }
}
