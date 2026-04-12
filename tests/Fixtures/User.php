<?php

namespace Huwiya\Tests\Fixtures;

use Huwiya\HasHuwiyaTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory, HasHuwiyaTokens;

    protected $fillable = ['name', 'huwiya_id', 'phone'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
