<?php

namespace Hawia\Tests\Fixtures;

use Hawia\HasHawiaTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory, HasHawiaTokens;

    protected $fillable = ['name', 'hawia_id', 'phone'];
}
