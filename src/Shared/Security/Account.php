<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

final class Account
{
    public function __construct(
        public readonly string $publicUserId,
        public readonly string $status,
        public readonly bool $mfaRequired,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
