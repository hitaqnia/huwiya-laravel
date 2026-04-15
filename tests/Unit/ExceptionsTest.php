<?php

use Huwiya\Exceptions\AuthConfigurationException;
use Huwiya\Exceptions\HuwiyaException;
use Huwiya\Exceptions\HuwiyaUserNotFoundException;
use Huwiya\Exceptions\InvalidJwtFormatException;
use Huwiya\Exceptions\InvalidStateException;
use Huwiya\Exceptions\InvalidTokenClaimsException;
use Huwiya\Exceptions\JwksFetchException;
use Huwiya\Exceptions\TokenExchangeException;
use Huwiya\Exceptions\UnknownKidException;
use Huwiya\Exceptions\UnsupportedKeyTypeException;

it('makes every package exception a HuwiyaException', function (string $class) {
    expect(is_subclass_of($class, HuwiyaException::class))->toBeTrue();
})->with([
    AuthConfigurationException::class,
    HuwiyaUserNotFoundException::class,
    InvalidJwtFormatException::class,
    InvalidStateException::class,
    InvalidTokenClaimsException::class,
    JwksFetchException::class,
    TokenExchangeException::class,
    UnknownKidException::class,
    UnsupportedKeyTypeException::class,
]);

it('extends RuntimeException so host apps can catch broadly', function () {
    expect(is_subclass_of(HuwiyaException::class, RuntimeException::class))->toBeTrue();
});

it('builds InvalidTokenClaimsException with missingKeys factory', function () {
    $exception = InvalidTokenClaimsException::missingKeys(['id', 'locale']);

    expect($exception->getMessage())->toContain('id')
        ->and($exception->getMessage())->toContain('locale');
});

it('builds InvalidTokenClaimsException with invalidUlid factory', function () {
    $exception = InvalidTokenClaimsException::invalidUlid('bad-id');

    expect($exception->getMessage())->toContain('bad-id')
        ->and($exception->getMessage())->toContain('ULID');
});

it('builds UnknownKidException with kid factory', function () {
    $exception = UnknownKidException::forKid('abc-123');

    expect($exception->getMessage())->toContain('abc-123');
});

it('builds UnsupportedKeyTypeException with kty factory', function () {
    $exception = UnsupportedKeyTypeException::forKty('abc-123', 'EC');

    expect($exception->getMessage())->toContain('abc-123')
        ->and($exception->getMessage())->toContain('EC');
});
