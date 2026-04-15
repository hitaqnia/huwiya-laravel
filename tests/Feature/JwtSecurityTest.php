<?php

use Huwiya\Tests\Fixtures\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config([
        'huwiya.url' => 'https://idp.example.com',
        'huwiya.client_id' => 'test-client-id',
        'huwiya.project_id' => 'test-project-id',
        'huwiya.leeway' => 60,
        'huwiya.verify_signature' => true,
        'huwiya.algorithm' => 'RS256',
        'huwiya.validate_issuer' => true,
        'huwiya.validate_audience' => true,
        'auth.guards.api' => [
            'driver' => 'huwiya-api',
            'provider' => 'users',
        ],
    ]);

    seedTestJwksCache();

    Route::middleware('auth:api')->get('/test/secure', function () {
        return response()->json([
            'id' => auth('api')->user()->id,
        ]);
    });
});

it('rejects JWT with alg:none header (algorithm confusion attack)', function () {
    $user = User::factory()->create();

    // Manually craft a JWT with alg:none
    $header = rtrim(strtr(base64_encode(json_encode([
        'alg' => 'none',
        'typ' => 'JWT',
        'kid' => 'test-kid',
    ])), '+/', '-_'), '=');

    $payload = rtrim(strtr(base64_encode(json_encode([
        'id' => $user->huwiya_id,
        'name' => $user->name,
        'iss' => 'https://idp.example.com',
        'aud' => 'test-project-id',
        'exp' => time() + 3600,
    ])), '+/', '-_'), '=');

    $jwt = "{$header}.{$payload}.";

    $this->getJson('/test/secure', ['Authorization' => "Bearer {$jwt}"])
        ->assertUnauthorized();
});

it('rejects JWT with alg:HS256 header (algorithm downgrade attack)', function () {
    $user = User::factory()->create();

    $jwt = createTestJwt([
        'id' => $user->huwiya_id,
        'name' => $user->name,
        'iss' => 'https://idp.example.com',
        'aud' => 'test-project-id',
    ], headerOverrides: ['alg' => 'HS256']);

    $this->getJson('/test/secure', ['Authorization' => "Bearer {$jwt}"])
        ->assertUnauthorized();
});

it('rejects JWT with wrong audience claim', function () {
    $user = User::factory()->create();

    $jwt = createTestJwt([
        'id' => $user->huwiya_id,
        'name' => $user->name,
        'iss' => 'https://idp.example.com',
        'aud' => 'wrong-project-id',
    ]);

    $this->getJson('/test/secure', ['Authorization' => "Bearer {$jwt}"])
        ->assertUnauthorized();
});

it('rejects JWT with wrong issuer claim', function () {
    $user = User::factory()->create();

    $jwt = createTestJwt([
        'id' => $user->huwiya_id,
        'name' => $user->name,
        'iss' => 'https://evil-idp.example.com',
        'aud' => 'test-project-id',
    ]);

    $this->getJson('/test/secure', ['Authorization' => "Bearer {$jwt}"])
        ->assertUnauthorized();
});

it('accepts JWT with correct issuer and audience', function () {
    $user = User::factory()->create();

    $jwt = createTestJwt([
        'id' => $user->huwiya_id,
        'name' => $user->name,
        'iss' => 'https://idp.example.com',
        'aud' => 'test-project-id',
    ]);

    $this->getJson('/test/secure', ['Authorization' => "Bearer {$jwt}"])
        ->assertSuccessful();
});

it('skips issuer validation when disabled', function () {
    config(['huwiya.validate_issuer' => false]);

    $user = User::factory()->create();

    $jwt = createTestJwt([
        'id' => $user->huwiya_id,
        'name' => $user->name,
        'iss' => 'https://any-issuer.example.com',
        'aud' => 'test-project-id',
    ]);

    $this->getJson('/test/secure', ['Authorization' => "Bearer {$jwt}"])
        ->assertSuccessful();
});

it('skips audience validation when disabled', function () {
    config(['huwiya.validate_audience' => false]);

    $user = User::factory()->create();

    $jwt = createTestJwt([
        'id' => $user->huwiya_id,
        'name' => $user->name,
        'iss' => 'https://idp.example.com',
        'aud' => 'any-audience',
    ]);

    $this->getJson('/test/secure', ['Authorization' => "Bearer {$jwt}"])
        ->assertSuccessful();
});

it('rejects JWT without kid header', function () {
    $user = User::factory()->create();

    $jwt = createTestJwt([
        'id' => $user->huwiya_id,
        'name' => $user->name,
        'iss' => 'https://idp.example.com',
        'aud' => 'test-project-id',
    ], headerOverrides: ['kid' => '']);

    $this->getJson('/test/secure', ['Authorization' => "Bearer {$jwt}"])
        ->assertUnauthorized();
});
