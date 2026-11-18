<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Audit;

/** Wer eine auditierte Handlung ausloest: ein Konto oder die CLI (Betreiber). */
final class Actor
{
    private function __construct(
        public readonly string $type,
        public readonly ?string $accountId,
    ) {
    }

    public static function cli(): self
    {
        return new self('cli', null);
    }

    /** @param string $publicUserId public_user_id des handelnden Kontos */
    public static function account(string $publicUserId): self
    {
        return new self('account', $publicUserId);
    }
}
