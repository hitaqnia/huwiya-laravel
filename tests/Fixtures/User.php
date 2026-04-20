<?php

namespace Huwiya\Tests\Fixtures;

use Huwiya\InteractsWithHuwiya;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory, InteractsWithHuwiya;

    protected $guarded = [];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
