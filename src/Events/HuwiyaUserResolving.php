<?php

namespace Huwiya\Events;

use Huwiya\TokenClaims;
use Illuminate\Database\Eloquent\Builder;

class HuwiyaUserResolving
{
    public function __construct(
        public readonly TokenClaims $claims,
        public readonly Builder $query,
        public readonly ?string $guard = null,
    ) {}
}
