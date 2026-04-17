<?php

namespace Huwiya\Events;

use Huwiya\TokenClaims;

class HuwiyaUserCreating
{
    public function __construct(
        public readonly TokenClaims $claims,
        public readonly ?string $guard = null,
    ) {}
}
