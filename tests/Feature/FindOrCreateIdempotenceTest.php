<?php

use Huwiya\TokenClaims;
use Huwiya\Tests\Fixtures\User;

it('is idempotent when called repeatedly with the same subject identifier', function () {
    $claims = TokenClaims::fromArray([
        'sub' => 'stable-subject-id',
        'name' => 'Repeat User',
        'phone' => '+10000000001',
    ]);

    $first = User::findOrCreateFromHuwiya($claims);
    $second = User::findOrCreateFromHuwiya($claims);
    $third = User::findOrCreateFromHuwiya($claims);

    expect($first->id)->toBe($second->id)
        ->and($second->id)->toBe($third->id)
        ->and(User::where('huwiya_id', 'stable-subject-id')->count())->toBe(1);
});

it('updates existing user attributes on repeat login', function () {
    User::factory()->create([
        'huwiya_id' => 'existing-sub',
        'name' => 'Old Name',
        'phone' => '+10000000099',
    ]);

    $claims = TokenClaims::fromArray([
        'sub' => 'existing-sub',
        'name' => 'New Name',
        'phone' => '+10000000100',
    ]);

    $user = User::findOrCreateFromHuwiya($claims);

    expect($user->fresh()->name)->toBe('New Name')
        ->and($user->fresh()->phone)->toBe('+10000000100')
        ->and(User::where('huwiya_id', 'existing-sub')->count())->toBe(1);
});
