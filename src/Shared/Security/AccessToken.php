<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

final class AccessToken
{
    public function __construct(
        public readonly string $subject,
        public readonly ?int $issuedAt,
        public readonly int $expiresAt,
        public readonly bool $usedMfa,
    ) {
    }
}
