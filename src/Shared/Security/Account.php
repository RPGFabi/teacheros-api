<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

final class Account
{
    /**
     * @param int|null $sessionsValidAfter Unix-Zeit: Tokens mit `iat` bis einschliesslich dieser Zeit sind widerrufen (ADR 0002 B2); null = keine Grenze
     * @param int|null $inactiveSince Unix-Zeit der Deaktivierung
     * @param list<OpenCall> $openCalls "Abgleich offen": die dem Anmeldedienst noch nicht gemeldeten Aufrufe dieses Kontos (Ticket #47), ungeordnet; die feste Reihenfolge kennt {@see OpenCalls}
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
        public readonly array $openCalls = [],
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    /** Der offene Aufruf $operation dieses Kontos oder null, wenn er nicht offen ist (Ticket #47). */
    public function openCall(string $operation): ?OpenCall
    {
        foreach ($this->openCalls as $call) {
            if ($call->operation === $operation) {
                return $call;
            }
        }
        return null;
    }

    /** "Abgleich offen" der Sperre: ein deaktiviertes Konto, dessen Deaktivierung dem Anmeldedienst noch nicht gemeldet ist (Ticket #42). */
    public function awaitsLoginSync(): bool
    {
        return $this->status === AccountStatus::Inactive && $this->openCall(DisableLoginCall::OPERATION) !== null;
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
