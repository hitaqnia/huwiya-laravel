<?php

use Huwiya\Tests\Fixtures\User;
use Huwiya\TokenClaims;
use Illuminate\Support\Str;

function makeTestClaims(array $overrides = []): TokenClaims
{
    return new TokenClaims(
        id: $overrides['id'] ?? (string) Str::ulid(),
        name: $overrides['name'] ?? 'Test User',
        phone: $overrides['phone'] ?? '+9647'.random_int(10_000_000, 99_999_999),
        email: $overrides['email'] ?? 'user-'.bin2hex(random_bytes(4)).'@example.com',
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
        'phone' => $user->phone,
        'email' => $user->email,
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
        ->and($user->name)->toBe('New User')
        ->and($user->phone)->toBe($claims->phone)
        ->and($user->email)->toBe($claims->email);
});

it('uses the configured identifier column', function () {
    $user = User::factory()->create();

    expect($user->getHuwiyaIdentifierColumn())->toBe('huwiya_id');
});

it('returns default create and update attributes from the fields map', function () {
    $user = new User;
    $claims = makeTestClaims([
        'name' => 'Test User',
        'phone' => '+964123456789',
        'email' => 'test@example.com',
    ]);

    $expected = [
        'phone' => '+964123456789',
        'email' => 'test@example.com',
        'name' => 'Test User',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
    ];

    expect($user->getHuwiyaCreateAttributes($claims))->toBe($expected);
    expect($user->getHuwiyaUpdateAttributes($claims))->toBe($expected);
});
