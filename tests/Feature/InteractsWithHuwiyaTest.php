<?php

use Huwiya\Tests\Fixtures\User;
use Huwiya\TokenClaims;
use Illuminate\Support\Str;

function makeTestClaims(array $overrides = []): TokenClaims
{
    return new TokenClaims(
        id: $overrides['id'] ?? (string) Str::ulid(),
        name: $overrides['name'] ?? 'Test User',
        locale: $overrides['locale'] ?? 'en',
        zoneinfo: $overrides['zoneinfo'] ?? 'Asia/Baghdad',
        theme: $overrides['theme'] ?? 'light',
        scopes: $overrides['scopes'] ?? [],
    );
}

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

    $claims = makeTestClaims([
        'id' => $user->huwiya_id,
        'name' => 'New Name',
    ]);

    $result = User::findOrCreateFromHuwiya($claims);

    expect($result->id)->toBe($user->id)
        ->and($result->name)->toBe('New Name');
});

it('creates a new user from claims when auto-registration is enabled', function () {
    $newId = (string) Str::ulid();

    $claims = makeTestClaims([
        'id' => $newId,
        'name' => 'New User',
    ]);

    $user = User::findOrCreateFromHuwiya($claims);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->exists)->toBeTrue()
        ->and($user->huwiya_id)->toBe($newId)
        ->and($user->name)->toBe('New User');
});

it('uses the configured identifier column', function () {
    $user = User::factory()->create();

    expect($user->getHuwiyaIdentifierColumn())->toBe('huwiya_id');
});

it('returns default create and update attributes', function () {
    $user = new User;
    $claims = makeTestClaims(['name' => 'Test User']);

    expect($user->getHuwiyaCreateAttributes($claims))->toBe([
        'name' => 'Test User',
    ]);

    expect($user->getHuwiyaUpdateAttributes($claims))->toBe([
        'name' => 'Test User',
    ]);
});
