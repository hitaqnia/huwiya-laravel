<?php

namespace Huwiya\Tests\Fixtures;

use Huwiya\InteractsWithHuwiya;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Fixture with invitations enabled and auto-registration disabled.
 * Shares the `users` table with the default User fixture.
 */
class InvitableUser extends Authenticatable
{
    use HasFactory, InteractsWithHuwiya;

    protected $table = 'users';

    protected $guarded = [];

    public function shouldAutoRegister(?\Huwiya\TokenClaims $claims = null): bool
    {
        return false;
    }

    public function invitationsEnabled(): bool
    {
        return true;
    }
}
