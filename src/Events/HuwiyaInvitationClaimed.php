<?php

namespace Huwiya\Events;

use Huwiya\TokenClaims;
use Illuminate\Contracts\Auth\Authenticatable;

class HuwiyaInvitationClaimed
{
    public function __construct(
        public readonly TokenClaims $claims,
        public readonly Authenticatable $user,
        public readonly ?string $guard = null,
    ) {}
}
