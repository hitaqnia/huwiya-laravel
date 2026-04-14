<?php

use Huwiya\Exceptions\AuthConfigurationException;
use Huwiya\Tests\Fixtures\User;
use Huwiya\Tests\Fixtures\UserWithoutTrait;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config([
        'huwiya.verify_signature' => true,
        'huwiya.validate_issuer' => false,
        'huwiya.validate_audience' => false,
    ]);

    seedTestJwksCache();
});

it('throws AuthConfigurationException when the auth provider model is missing', function () {
    config([
        'auth.providers.users.model' => null,
        'auth.guards.api' => ['driver' => 'huwiya-api', 'provider' => 'users'],
    ]);

    Route::middleware('auth:api')->get('/test/cfg', fn () => response()->json([]));

    $user = User::factory()->create();
    $jwt = createTestJwt([
        'sub' => $user->huwiya_id,
        'name' => $user->name,
        'phone' => $user->phone,
    ]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/test/cfg', ['Authorization' => "Bearer {$jwt}"]))
        ->toThrow(AuthConfigurationException::class);
});

it('throws AuthConfigurationException when the model does not use InteractsWithHuwiya', function () {
    config([
        'auth.providers.users.model' => UserWithoutTrait::class,
        'auth.guards.api' => ['driver' => 'huwiya-api', 'provider' => 'users'],
    ]);

    Route::middleware('auth:api')->get('/test/cfg', fn () => response()->json([]));

    $user = User::factory()->create();
    $jwt = createTestJwt([
        'sub' => $user->huwiya_id,
        'name' => $user->name,
        'phone' => $user->phone,
    ]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/test/cfg', ['Authorization' => "Bearer {$jwt}"]))
        ->toThrow(AuthConfigurationException::class, 'must use the InteractsWithHuwiya trait');
});
