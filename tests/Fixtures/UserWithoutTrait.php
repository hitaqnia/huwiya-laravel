<?php

namespace Huwiya\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class UserWithoutTrait extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = ['name', 'huwiya_id', 'phone'];
}
