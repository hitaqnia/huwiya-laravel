<?php

use Huwiya\Tests\Fixtures\User;
use Huwiya\TokenClaims;
use Illuminate\Support\Str;

it('is idempotent when called repeatedly with the same subject identifier', function () {
    $id = (string) Str::ulid();

    $claims = TokenClaims::fromArray([
        'id' => $id,
        'name' => 'Repeat User',
        'phone' => '+9647700000001',
        'email' => 'repeat@example.com',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => [],
    ]);

    $first = User::findOrCreateFromHuwiya($claims);
    $second = User::findOrCreateFromHuwiya($claims);
    $third = User::findOrCreateFromHuwiya($claims);

    expect($first->id)->toBe($second->id)
        ->and($second->id)->toBe($third->id)
        ->and(User::where('huwiya_id', $id)->count())->toBe(1);
});

it('updates existing user attributes on repeat login', function () {
    $id = (string) Str::ulid();

    User::factory()->create([
        'huwiya_id' => $id,
        'name' => 'Old Name',
    ]);

    $claims = TokenClaims::fromArray([
        'id' => $id,
        'name' => 'New Name',
        'phone' => '+9647700000002',
        'email' => 'new@example.com',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => [],
    ]);

    $user = User::findOrCreateFromHuwiya($claims);

    expect($user->fresh()->name)->toBe('New Name')
        ->and(User::where('huwiya_id', $id)->count())->toBe(1);
});
