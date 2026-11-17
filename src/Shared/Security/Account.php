<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

final class Account
{
    public function __construct(
        public readonly string $publicUserId,
        public readonly string $status,
        public readonly bool $mfaRequired,
        public readonly ?string $email = null,
        public readonly string $loginName = '',
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}