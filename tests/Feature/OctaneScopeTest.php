<?php

use Huwiya\Huwiya;
use Huwiya\Support\AuthorizationDeniedCallback;

it('resets the authorization-denied callback when the container scope is cleared', function () {
    Huwiya::whenAuthorizationDenied(fn () => 'first-request');

    expect(app(AuthorizationDeniedCallback::class)->isSet())->toBeTrue();

    // Simulate Octane's per-request container reset.
    app()->forgetScopedInstances();

    expect(app(AuthorizationDeniedCallback::class)->isSet())->toBeFalse()
        ->and(Huwiya::denied())->not->toBe('first-request');
});

it('scopes the authorization-denied callback to the current container', function () {
    Huwiya::whenAuthorizationDenied(fn () => 'from-this-scope');

    expect(Huwiya::denied())->toBe('from-this-scope');

    Huwiya::flush();

    expect(app(AuthorizationDeniedCallback::class)->isSet())->toBeFalse();
});
