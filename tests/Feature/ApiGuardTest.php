<?php

use Hawia\Tests\Fixtures\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config([
        'huwiya.leeway' => 60,
        'huwiya.verify_signature' => true,
        'huwiya.validate_issuer' => false,
        'huwiya.validate_audience' => false,
        'auth.guards.api' => [
            'driver' => 'hawia-api',
            'provider' => 'users',
        ],
    ]);

    seedTestJwksCache();

    Route::middleware('auth:api')->get('/test/me', function () {
        return response()->json([
            'id' => auth('api')->user()->id,
            'name' => auth('api')->user()->name,
        ]);
    });
});

it('authenticates a user with a valid JWT bearer token', function () {
    $user = User::factory()->create();

    $jwt = createTestJwt([
        'sub' => $user->hawia_id,
        'name' => $user->name,
        'phone' => $user->phone,
    ]);

    $this->getJson('/test/me', ['Authorization' => "Bearer {$jwt}"])
        ->assertSuccessful()
        ->assertJson([
            'id' => $user->id,
            'name' => $user->name,
        ]);
});

it('rejects requests without a bearer token', function () {
    $this->getJson('/test/me')
        ->assertUnauthorized();
});

it('rejects requests with an invalid JWT', function () {
    $this->getJson('/test/me', ['Authorization' => 'Bearer not-a-valid-jwt'])
        ->assertUnauthorized();
});

it('rejects requests with a tampered JWT payload', function () {
    $user = User::factory()->create();

    $jwt = createTestJwt([
        'sub' => $user->hawia_id,
        'name' => $user->name,
        'phone' => $user->phone,
    ]);

    // Tamper with the payload
    $parts = explode('.', $jwt);
    $parts[1] = rtrim(strtr(base64_encode(json_encode([
        'sub' => 'hacker-id',
        'name' => 'Hacker',
        'phone' => '+000',
        'exp' => time() + 3600,
    ])), '+/', '-_'), '=');

    $tampered = implode('.', $parts);

    $this->getJson('/test/me', ['Authorization' => "Bearer {$tampered}"])
        ->assertUnauthorized();
});

it('rejects requests with an expired JWT', function () {
    $user = User::factory()->create();

    $jwt = createTestJwt([
        'sub' => $user->hawia_id,
        'name' => $user->name,
        'phone' => $user->phone,
        'exp' => time() - 200,
    ]);

    $this->getJson('/test/me', ['Authorization' => "Bearer {$jwt}"])
        ->assertUnauthorized();
});

it('allows tokens within the leeway window', function () {
    $user = User::factory()->create();

    $jwt = createTestJwt([
        'sub' => $user->hawia_id,
        'name' => $user->name,
        'phone' => $user->phone,
        'exp' => time() - 30,
    ]);

    $this->getJson('/test/me', ['Authorization' => "Bearer {$jwt}"])
        ->assertSuccessful();
});

it('auto-registers a new user from a valid JWT', function () {
    $jwt = createTestJwt([
        'sub' => 'new-hawia-id',
        'name' => 'New User',
        'phone' => '+1234567890',
    ]);

    $this->getJson('/test/me', ['Authorization' => "Bearer {$jwt}"])
        ->assertSuccessful()
        ->assertJson([
            'name' => 'New User',
        ]);

    $this->assertDatabaseHas('users', [
        'hawia_id' => 'new-hawia-id',
        'name' => 'New User',
        'phone' => '+1234567890',
    ]);
});

it('attaches token claims to the authenticated user', function () {
    $user = User::factory()->create();

    Route::middleware('auth:api')->get('/test/claims', function () {
        $user = auth('api')->user();

        return response()->json([
            'has_token' => $user->hawiaToken !== null,
            'token_id' => $user->hawiaToken?->id,
        ]);
    });

    $jwt = createTestJwt([
        'sub' => $user->hawia_id,
        'name' => $user->name,
        'phone' => $user->phone,
    ]);

    $this->getJson('/test/claims', ['Authorization' => "Bearer {$jwt}"])
        ->assertSuccessful()
        ->assertJson([
            'has_token' => true,
            'token_id' => $user->hawia_id,
        ]);
});

it('skips signature verification when disabled', function () {
    config(['huwiya.verify_signature' => false, 'huwiya.public_key' => null]);

    $user = User::factory()->create();

    // Create a JWT with a completely random signature (no valid key)
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode([
        'sub' => $user->hawia_id,
        'name' => $user->name,
        'phone' => $user->phone,
        'exp' => time() + 3600,
    ])), '+/', '-_'), '=');
    $fakeSignature = rtrim(strtr(base64_encode('not-a-real-signature'), '+/', '-_'), '=');

    $jwt = "{$header}.{$payload}.{$fakeSignature}";

    $this->getJson('/test/me', ['Authorization' => "Bearer {$jwt}"])
        ->assertSuccessful()
        ->assertJson(['id' => $user->id]);
});

it('rejects JWT signed with a different key', function () {
    $user = User::factory()->create();

    $wrongKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $jwt = createTestJwt([
        'sub' => $user->hawia_id,
        'name' => $user->name,
        'phone' => $user->phone,
    ], $wrongKey);

    $this->getJson('/test/me', ['Authorization' => "Bearer {$jwt}"])
        ->assertUnauthorized();
});
