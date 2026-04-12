<?php

use Hawia\Tests\Fixtures\User;
use Hawia\Hawia;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config([
        'auth.guards.web' => [
            'driver' => 'hawia-web',
            'provider' => 'users',
        ],
    ]);

    Route::middleware(['web', 'auth:web'])->get('/test/dashboard', function () {
        return response()->json([
            'id' => auth('web')->user()->id,
            'name' => auth('web')->user()->name,
        ]);
    });
});

it('authenticates a user through the web guard via session', function () {
    $user = User::factory()->create();

    $sessionKey = 'login_web_'.sha1('Illuminate\Auth\SessionGuard');

    $this->withSession([$sessionKey => $user->id])
        ->get('/test/dashboard')
        ->assertSuccessful()
        ->assertJson([
            'id' => $user->id,
            'name' => $user->name,
        ]);
});

it('rejects unauthenticated requests', function () {
    $this->getJson('/test/dashboard')
        ->assertUnauthorized();
});

it('supports actingAs helper for testing', function () {
    $user = User::factory()->create();

    Hawia::actingAs($user, 'web');

    $this->getJson('/test/dashboard')
        ->assertSuccessful()
        ->assertJson(['id' => $user->id]);
});
