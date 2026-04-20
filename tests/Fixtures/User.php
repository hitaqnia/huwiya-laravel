<?php

namespace Huwiya\Tests\Fixtures;

use Huwiya\InteractsWithHuwiya;
use Huwiya\TokenClaims;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Test fixture that widens the trait's minimal default sync set to match
 * what a typical app would do: persist phone, email, and name from claims
 * so the `users` table's NOT NULL phone column is satisfied.
 */
class User extends Authenticatable
{
    use HasFactory, InteractsWithHuwiya;

    protected $guarded = [];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function getHuwiyaCreateAttributes(TokenClaims $claims): array
    {
        return [
            'name' => $claims->name,
            'phone' => $claims->phone,
            'email' => $claims->email,
        ];
    }

    public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
    {
        return [
            'name' => $claims->name,
            'phone' => $claims->phone,
            'email' => $claims->email,
        ];
    }
}
