<?php

use Huwiya\Exceptions\AuthConfigurationException;
use Huwiya\Exceptions\InvalidGuardException;
use Huwiya\Huwiya;
use Huwiya\Tests\Fixtures\User;
use Huwiya\Tests\Fixtures\UserWithoutTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'huwiya.url' => 'https://idp.example.com',
        'huwiya.client_id' => 'test-client-id',
        'huwiya.client_secret' => 'test-client-secret',
        'huwiya.redirect_uri' => 'https://app.test/huwiya/callback',
        'auth.guards.web' => [
            'driver' => 'huwiya-web',
            'provider' => 'users',
        ],
    ]);
});

it('throws when the guard name is empty', function () {
    Huwiya::redirect('');
})->throws(InvalidGuardException::class, 'A guard name is required.');

it('throws when the guard is not configured', function () {
    Huwiya::redirect('nonexistent');
})->throws(InvalidGuardException::class, 'not configured');

it('rejects guards that use the huwiya-api driver', function () {
    config(['auth.guards.api' => ['driver' => 'huwiya-api', 'provider' => 'users']]);

    Huwiya::redirect('api');
})->throws(InvalidGuardException::class, 'must use the [huwiya-web] driver');

it('rejects guards that use plain non-huwiya drivers', function () {
    config(['auth.guards.legacy' => ['driver' => 'session', 'provider' => 'users']]);

    Huwiya::redirect('legacy');
})->throws(InvalidGuardException::class, 'must use the [huwiya-web] driver');

it('throws when the provider model is missing', function () {
    config([
        'auth.providers.orphans' => ['driver' => 'eloquent'],
        'auth.guards.orphan' => ['driver' => 'huwiya-web', 'provider' => 'orphans'],
    ]);

    Huwiya::redirect('orphan');
})->throws(AuthConfigurationException::class, 'Unable to determine user model');

it('throws when the provider model does not use InteractsWithHuwiya', function () {
    config([
        'auth.providers.plain' => ['driver' => 'eloquent', 'model' => UserWithoutTrait::class],
        'auth.guards.plain' => ['driver' => 'huwiya-web', 'provider' => 'plain'],
    ]);

    Huwiya::redirect('plain');
})->throws(AuthConfigurationException::class, 'must use the InteractsWithHuwiya trait');

it('returns a redirect and stores the bound payload', function () {
    $response = Huwiya::redirect('web');

    expect($response->getTargetUrl())->toStartWith('https://idp.example.com/oauth/authorize');

    $payload = session('huwiya.oauth');

    expect($payload)->toBeArray()
        ->and($payload['state'])->toBeString()->toHaveLength(40)
        ->and($payload['guard'])->toBe('web');
});

it('logs the user into the guard bound at redirect time, not the default', function () {
    config([
        'auth.guards.admin' => ['driver' => 'huwiya-web', 'provider' => 'users'],
    ]);

    $huwiyaId = (string) \Illuminate\Support\Str::ulid();

    $jwt = createTestJwt([
        'id' => $huwiyaId,
        'name' => 'Admin User',
    ]);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'admin']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state');

    expect(Auth::guard('admin')->check())->toBeTrue()
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('refuses to log in when the bound guard is no longer huwiya-web', function () {
    // Between redirect and callback the operator changed the driver.
    // Defense in depth: we must refuse to log in even though state matches.
    config(['auth.guards.web.driver' => 'session']);

    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state')
        ->assertStatus(500);
});

it('refuses to log in when the bound guard has been removed entirely', function () {
    config(['auth.guards.web' => null]);

    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state')
        ->assertStatus(500);
});

it('rejects malformed session payloads', function () {
    $this->withSession(['huwiya.oauth' => ['state' => 'valid-state']])
        ->get('/huwiya/callback?code=auth-code&state=valid-state')
        ->assertStatus(500);
});

it('consumes the session payload so it cannot be replayed', function () {
    User::factory()->create(['huwiya_id' => $id = (string) \Illuminate\Support\Str::ulid()]);

    $jwt = createTestJwt(['id' => $id, 'name' => 'Replay']);

    Http::fake([
        'idp.example.com/oauth/token' => Http::response([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
        ]),
    ]);

    $session = ['huwiya.oauth' => ['state' => 'valid-state', 'guard' => 'web']];

    $this->withSession($session)
        ->get('/huwiya/callback?code=auth-code&state=valid-state')
        ->assertRedirect('/');

    // Re-seeding the session is required because withSession starts a new session
    // for the next request. But the flash in the *same* session is what matters —
    // the first call's pull() should have cleared it. Prove by making a second
    // callback attempt without seeding: it fails on the missing payload.
    $this->get('/huwiya/callback?code=auth-code&state=valid-state')
        ->assertStatus(500);
});
