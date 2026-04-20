<?php

namespace Huwiya\Tests\Fixtures;

use Huwiya\InteractsWithHuwiya;
use Huwiya\TokenClaims;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Fixture with auto-registration disabled — used to exercise the "user
 * not found" callback branch. Shares the `users` table with the default
 * User fixture.
 */
class StrictUser extends Authenticatable
{
    use InteractsWithHuwiya;

    protected $table = 'users';

    protected $guarded = [];

    public function shouldAutoRegister(?TokenClaims $claims = null): bool
    {
        return false;
    }
}
