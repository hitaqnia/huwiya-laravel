<?php

namespace Huwiya\Events;

use Huwiya\TokenClaims;

class HuwiyaAuthenticating
{
    public function __construct(
        public readonly TokenClaims $claims,
        public readonly ?string $guard = null,
    ) {}
}
