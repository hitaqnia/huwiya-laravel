<?php

use Huwiya\Facades\Huwiya as HuwiyaFacade;
use Huwiya\Huwiya;
use Huwiya\Support\AuthorizationDeniedCallback;

it('resolves the facade accessor', function () {
    expect(HuwiyaFacade::sessionKeyForGuard('web'))
        ->toBe(Huwiya::sessionKeyForGuard('web'));
});

it('routes whenAuthorizationDenied through the container holder', function () {
    HuwiyaFacade::whenAuthorizationDenied(fn () => 'facade-wins');

    expect(app(AuthorizationDeniedCallback::class)->isSet())->toBeTrue()
        ->and(HuwiyaFacade::denied())->toBe('facade-wins');

    HuwiyaFacade::flush();

    expect(app(AuthorizationDeniedCallback::class)->isSet())->toBeFalse();
});

it('passes error and description to arity-aware denied callbacks', function () {
    $captured = [];

    HuwiyaFacade::whenAuthorizationDenied(function (?string $error, ?string $description) use (&$captured) {
        $captured = [$error, $description];

        return 'ok';
    });

    HuwiyaFacade::denied('access_denied', 'user refused');

    expect($captured)->toBe(['access_denied', 'user refused']);

    HuwiyaFacade::flush();
});

it('invokes zero-arity denied callbacks without error args', function () {
    $invoked = false;

    HuwiyaFacade::whenAuthorizationDenied(function () use (&$invoked) {
        $invoked = true;

        return 'ok';
    });

    HuwiyaFacade::denied('access_denied', 'user refused');

    expect($invoked)->toBeTrue();

    HuwiyaFacade::flush();
});
