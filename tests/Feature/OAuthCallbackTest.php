<?php

use Huwiya\Huwiya;
use Huwiya\Tests\Fixtures\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'huwiya.url' => 'https://idp.example.com',
        'huwiya.client_id' => 'test-client-id',
        'huwiya.client_secret' => 'test-client-secret',
        'huwiya.redirect_uri' => 'https://app.test/huwiya/callback',
        // The callback now verifies tokens end-to-end. These tests focus on the
        // OAuth transport, not signature/claim validation (covered in JwtSecurityTest),
        // so signature verification and issuer/audience checks are disabled here.
        'huwiya.verify_signature' => false,
        'huwiya.validate_issuer' => false,
        'huwiya.validate_audience' => false,
        'auth.guards.web' => [
            'driver' => 'huwiya-web',
            'provider' => 'users',
        ],
    ]);
});

it('redirects to the IdP authorization endpoint', function () {
    $response = Huwiya::redirect('web');

    $location = $response->getTargetUrl();

    expect($location)->toStartWith('https://idp.example.com/oauth/authorize')
        ->and($location)->toContain('client_id=test-client-id')
        ->and($location)->toContain('response_type=code');
});

it('stores state and guard atomically in the session during redirect', function () {
    Huwiya::redirect('web');

    $payload = session('huwiya.oauth');

    expect($payload)->toBeArray()
        ->and($payload['state'])->toBeString()->toHaveLength(40)
        ->and($payload['guard'])->toBe('web');
});

it('exchanges code for token and creates a new user', function () {
    $newId = (string) \Illuminate\Support\Str::ulid();

    $jwt = createTestJwt([
        'id' => $newId,
        'name' => 'John Doe',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $response = $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state');

    $response->assertRedirect('/');
    $this->assertDatabaseHas('users', [
        'huwiya_id' => $newId,
        'name' => 'John Doe',
    ]);
});

it('updates an existing user on callback', function () {
    $user = User::factory()->create([
        'huwiya_id' => (string) \Illuminate\Support\Str::ulid(),
        'name' => 'Old Name',
    ]);

    $jwt = createTestJwt([
        'id' => $user->huwiya_id,
        'name' => 'Updated Name',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state');

    expect($user->fresh()->name)->toBe('Updated Name');
});

it('rejects callback with invalid state with a 400', function () {
    $this->withSession(['huwiya.oauth' => ['state' => 'correct-state', 'guard' => 'web']])
        ->get('/huwiya/callback?code=auth-code&state=wrong-state')
        ->assertStatus(400);
});

it('rejects callback with missing state with a 400', function () {
    $this->get('/huwiya/callback?code=auth-code&state=any')
        ->assertStatus(400);
});

it('rejects callback with missing code parameter with a 400', function () {
    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?state=valid-state')
        ->assertStatus(400);
});

it('handles authorization denial from IdP', function () {
    $response = $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?error=access_denied&state=valid-state');

    $response->assertRedirect('/');
});

it('sends client credentials via HTTP Basic Auth by default', function () {
    config(['huwiya.auth_method' => 'basic']);

    $jwt = createTestJwt([
        'id' => (string) \Illuminate\Support\Str::ulid(),
        'name' => 'Test',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization')
            && str_starts_with($request->header('Authorization')[0], 'Basic ')
            && ! str_contains($request->body(), 'client_secret');
    });
});

it('sends client credentials in body when auth_method is body', function () {
    config(['huwiya.auth_method' => 'body']);

    $jwt = createTestJwt([
        'id' => (string) \Illuminate\Support\Str::ulid(),
        'name' => 'Test',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state');

    Http::assertSent(function ($request) {
        return str_contains($request->body(), 'client_secret=test-client-secret')
            && str_contains($request->body(), 'client_id=test-client-id');
    });
});

it('redirects to intended URL after login', function () {
    $jwt = createTestJwt([
        'id' => (string) \Illuminate\Support\Str::ulid(),
        'name' => 'Test',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $response = $this->withSession([
        'huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web'],
        'url.intended' => '/dashboard',
    ])->get('/huwiya/callback?code=auth-code&state=valid-state');

    $response->assertRedirect('/dashboard');
});
