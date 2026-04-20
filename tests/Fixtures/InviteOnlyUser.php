<?php

namespace Huwiya\Tests\Fixtures;

use Huwiya\InteractsWithHuwiya;
use Huwiya\TokenClaims;
use Illuminate\Foundation\Auth\User as Authenticatable;

class InviteOnlyUser extends Authenticatable
{
    use InteractsWithHuwiya;

    protected $table = 'users';

    protected $guarded = [];

    public function shouldAutoRegister(?TokenClaims $claims = null): bool
    {
        return false;
    }
}
