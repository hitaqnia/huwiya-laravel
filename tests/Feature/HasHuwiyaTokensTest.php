<?php

use Huwiya\Tests\Fixtures\User;
use Huwiya\TokenClaims;

it('finds an existing user by huwiya id', function () {
    $user = User::factory()->create();

    $found = User::findByHuwiyaId($user->huwiya_id);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($user->id);
});

it('returns null when user is not found by huwiya id', function () {
    expect(User::findByHuwiyaId('nonexistent-id'))->toBeNull();
});

it('finds and updates an existing user from claims', function () {
    $user = User::factory()->create(['name' => 'Old Name']);

    $claims = new TokenClaims(
        id: $user->huwiya_id,
        name: 'New Name',
        phoneNumber: '+9999999999',
    );

    $result = User::findOrCreateFromHuwiya($claims);

    expect($result->id)->toBe($user->id)
        ->and($result->name)->toBe('New Name');
});

it('creates a new user from claims when auto-registration is enabled', function () {
    $claims = new TokenClaims(
        id: 'new-huwiya-id',
        name: 'New User',
        phoneNumber: '+1111111111',
    );

    $user = User::findOrCreateFromHuwiya($claims);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->exists)->toBeTrue()
        ->and($user->huwiya_id)->toBe('new-huwiya-id')
        ->and($user->name)->toBe('New User')
        ->and($user->phone)->toBe('+1111111111');
});

it('uses the configured identifier column', function () {
    $user = User::factory()->create();

    expect($user->getHuwiyaIdentifierColumn())->toBe('huwiya_id');
});

it('returns default create and update attributes', function () {
    $user = new User;
    $claims = new TokenClaims(
        id: 'test',
        name: 'Test User',
        phoneNumber: '+123',
    );

    expect($user->getHuwiyaCreateAttributes($claims))->toBe([
        'name' => 'Test User',
        'phone' => '+123',
    ]);

    expect($user->getHuwiyaUpdateAttributes($claims))->toBe([
        'name' => 'Test User',
        'phone' => '+123',
    ]);
});
