<?php

use Hawia\Tests\Fixtures\User;
use Hawia\TokenClaims;

it('finds an existing user by hawia id', function () {
    $user = User::factory()->create();

    $found = User::findByHawiaId($user->hawia_id);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($user->id);
});

it('returns null when user is not found by hawia id', function () {
    expect(User::findByHawiaId('nonexistent-id'))->toBeNull();
});

it('finds and updates an existing user from claims', function () {
    $user = User::factory()->create(['name' => 'Old Name']);

    $claims = new TokenClaims(
        id: $user->hawia_id,
        name: 'New Name',
        phoneNumber: '+9999999999',
    );

    $result = User::findOrCreateFromHawia($claims);

    expect($result->id)->toBe($user->id)
        ->and($result->name)->toBe('New Name');
});

it('creates a new user from claims when auto-registration is enabled', function () {
    $claims = new TokenClaims(
        id: 'new-hawia-id',
        name: 'New User',
        phoneNumber: '+1111111111',
    );

    $user = User::findOrCreateFromHawia($claims);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->exists)->toBeTrue()
        ->and($user->hawia_id)->toBe('new-hawia-id')
        ->and($user->name)->toBe('New User')
        ->and($user->phone)->toBe('+1111111111');
});

it('uses the configured identifier column', function () {
    $user = User::factory()->create();

    expect($user->getHawiaIdentifierColumn())->toBe('hawia_id');
});

it('returns default create and update attributes', function () {
    $user = new User;
    $claims = new TokenClaims(
        id: 'test',
        name: 'Test User',
        phoneNumber: '+123',
    );

    expect($user->getHawiaCreateAttributes($claims))->toBe([
        'name' => 'Test User',
        'phone' => '+123',
    ]);

    expect($user->getHawiaUpdateAttributes($claims))->toBe([
        'name' => 'Test User',
        'phone' => '+123',
    ]);
});
