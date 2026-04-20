<?php

namespace Huwiya\Tests\Fixtures;

use Huwiya\InteractsWithHuwiya;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Fixture with invitations AND auto-registration enabled.
 */
class InvitableAutoRegUser extends Authenticatable
{
    use InteractsWithHuwiya;

    protected $table = 'users';

    protected $guarded = [];

    public function invitationsEnabled(): bool
    {
        return true;
    }
}
