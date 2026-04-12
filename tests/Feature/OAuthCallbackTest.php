<?php

use Hawia\Tests\Fixtures\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'huwiya.url' => 'https://idp.example.com',
        'huwiya.client_id' => 'test-client-id',
        'huwiya.client_secret' => 'test-client-secret',
        'huwiya.redirect_uri' => 'https://app.test/hawia/callback',
        'huwiya.web_guard' => 'web',
        'auth.guards.web' => [
            'driver' => 'hawia-web',
            'provider' => 'users',
        ],
    ]);
});

it('redirects to the IdP authorization endpoint', function () {
    $response = $this->get('/hawia/redirect');

    $response->assertRedirect();

    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://idp.example.com/oauth/authorize')
        ->and($location)->toContain('client_id=test-client-id')
        ->and($location)->toContain('response_type=code');
});

it('stores state in the session during redirect', function () {
    $this->get('/hawia/redirect');

    expect(session('state'))->not->toBeNull()
        ->and(session('state'))->toHaveLength(40);
});

it('exchanges code for token and creates a new user', function () {
    $jwt = createTestJwt([
        'sub' => 'new-user-id',
        'name' => 'John Doe',
        'phone' => '+1234567890',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $response = $this->withSession(['state' => 'valid-state'])
        ->get('/hawia/callback?code=auth-code&state=valid-state');

    $response->assertRedirect('/');
    $this->assertDatabaseHas('users', [
        'hawia_id' => 'new-user-id',
        'name' => 'John Doe',
        'phone' => '+1234567890',
    ]);
});

it('updates an existing user on callback', function () {
    $user = User::factory()->create([
        'hawia_id' => 'existing-user-id',
        'name' => 'Old Name',
    ]);

    $jwt = createTestJwt([
        'sub' => 'existing-user-id',
        'name' => 'Updated Name',
        'phone' => $user->phone,
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->withSession(['state' => 'valid-state'])
        ->get('/hawia/callback?code=auth-code&state=valid-state');

    expect($user->fresh()->name)->toBe('Updated Name');
});

it('rejects callback with invalid state', function () {
    $this->withSession(['state' => 'correct-state'])
        ->get('/hawia/callback?code=auth-code&state=wrong-state')
        ->assertStatus(500);
});

it('rejects callback with missing state', function () {
    $this->get('/hawia/callback?code=auth-code&state=any')
        ->assertStatus(500);
});

it('handles authorization denial from IdP', function () {
    $response = $this->withSession(['state' => 'valid-state'])
        ->get('/hawia/callback?error=access_denied&state=valid-state');

    $response->assertRedirect('/');
});

it('sends client credentials via HTTP Basic Auth by default', function () {
    config(['huwiya.auth_method' => 'basic']);

    $jwt = createTestJwt([
        'sub' => 'user-id',
        'name' => 'Test',
        'phone' => '+222',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->withSession(['state' => 'valid-state'])
        ->get('/hawia/callback?code=auth-code&state=valid-state');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization')
            && str_starts_with($request->header('Authorization')[0], 'Basic ')
            && ! str_contains($request->body(), 'client_secret');
    });
});

it('sends client credentials in body when auth_method is body', function () {
    config(['huwiya.auth_method' => 'body']);

    $jwt = createTestJwt([
        'sub' => 'user-id',
        'name' => 'Test',
        'phone' => '+333',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->withSession(['state' => 'valid-state'])
        ->get('/hawia/callback?code=auth-code&state=valid-state');

    Http::assertSent(function ($request) {
        return str_contains($request->body(), 'client_secret=test-client-secret')
            && str_contains($request->body(), 'client_id=test-client-id');
    });
});

it('redirects to intended URL after login', function () {
    $jwt = createTestJwt([
        'sub' => 'user-id',
        'name' => 'Test',
        'phone' => '+111',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $response = $this->withSession([
        'state' => 'valid-state',
        'url.intended' => '/dashboard',
    ])->get('/hawia/callback?code=auth-code&state=valid-state');

    $response->assertRedirect('/dashboard');
});
