<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

final class AuthenticatedSession
{
    public function __construct(
        public readonly Account $account,
        public readonly AccessToken $token,
    ) {
    }
}
