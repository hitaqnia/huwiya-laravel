<?php

use Huwiya\Huwiya;
use Huwiya\Tests\Fixtures\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'huwiya.url' => 'https://idp.example.com',
        'huwiya.client_id' => 'test-client-id',
        'huwiya.client_secret' => 'test-client-secret',
        'huwiya.redirect_uri' => 'https://app.test/huwiya/callback',
        'huwiya.verify_signature' => false,
        'huwiya.validate_issuer' => false,
        'huwiya.validate_audience' => false,
        'auth.guards.web' => [
            'driver' => 'huwiya-web',
            'provider' => 'users',
        ],
    ]);
});

it('stores the intended URL in the bound session payload on redirect', function () {
    Huwiya::redirect('web', '/dashboard');

    $payload = session('huwiya.oauth');

    expect($payload)->toBeArray()
        ->and($payload['intended'] ?? null)->toBe('/dashboard');
});

it('omits the intended key when no intended URL is passed', function () {
    Huwiya::redirect('web');

    $payload = session('huwiya.oauth');

    expect($payload)->toBeArray()
        ->and(array_key_exists('intended', $payload))->toBeFalse();
});

it('redirects to the bound intended URL after callback when provided', function () {
    $jwt = createTestJwt([
        'id' => (string) Str::ulid(),
        'name' => 'John',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $response = $this->withSession([
        'huwiya.oauth' => [
            'state' => 'valid-state',
            'guard' => 'web',
            'intended' => '/my-custom-page',
        ],
    ])->get('/huwiya/callback?code=auth-code&state=valid-state');

    $response->assertRedirect('/my-custom-page');
});

it('falls back to config(huwiya.home) when no intended URL is bound', function () {
    config(['huwiya.home' => '/app-home']);

    $jwt = createTestJwt([
        'id' => (string) Str::ulid(),
        'name' => 'John',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $response = $this->withSession([
        'huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web'],
    ])->get('/huwiya/callback?code=auth-code&state=valid-state');

    $response->assertRedirect('/app-home');
});

it('returns 403 when auto-registration is disabled and user is unknown', function () {
    config(['auth.providers.users.model' => \Huwiya\Tests\Fixtures\StrictUser::class]);

    $jwt = createTestJwt([
        'id' => (string) Str::ulid(),
        'name' => 'John',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state')
        ->assertStatus(403);
});

it('returns 502 when the token endpoint is unreachable', function () {
    Http::fake([
        'idp.example.com/oauth/token' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6'),
    ]);

    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state')
        ->assertStatus(502);
});

it('returns 502 when the token endpoint returns non-2xx', function () {
    Http::fake([
        'idp.example.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state')
        ->assertStatus(502);
});

it('returns 401 when the token endpoint returns a malformed access_token', function () {
    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => 'not.a.valid-jwt',
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state')
        ->assertStatus(502);
});
