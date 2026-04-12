<?php

use Huwiya\TokenClaims;

it('creates claims from array', function () {
    $claims = TokenClaims::fromArray([
        'sub' => 'user-123',
        'name' => 'John Doe',
        'phone' => '+1234567890',
        'iss' => 'https://idp.example.com',
        'iat' => 1000000,
        'exp' => 2000000,
        'aud' => 'my-app',
    ]);

    expect($claims->id)->toBe('user-123')
        ->and($claims->name)->toBe('John Doe')
        ->and($claims->phoneNumber)->toBe('+1234567890')
        ->and($claims->issuer)->toBe('https://idp.example.com')
        ->and($claims->issuedAt)->toBe(1000000)
        ->and($claims->expiresAt)->toBe(2000000)
        ->and($claims->audience)->toBe('my-app');
});

it('creates claims with optional fields defaulting to null', function () {
    $claims = TokenClaims::fromArray([
        'sub' => 'user-123',
        'name' => 'John',
        'phone' => '+1234567890',
    ]);

    expect($claims->issuer)->toBeNull()
        ->and($claims->issuedAt)->toBeNull()
        ->and($claims->expiresAt)->toBeNull()
        ->and($claims->audience)->toBeNull();
});

it('decodes claims from a JWT string', function () {
    $payload = base64_encode(json_encode([
        'sub' => 'user-456',
        'name' => 'Jane Doe',
        'phone' => '+9876543210',
        'exp' => 9999999999,
    ]));

    $token = "eyJhbGciOiJSUzI1NiJ9.{$payload}.fake-signature";

    $claims = TokenClaims::fromJwt($token);

    expect($claims->id)->toBe('user-456')
        ->and($claims->name)->toBe('Jane Doe')
        ->and($claims->phoneNumber)->toBe('+9876543210');
});

it('throws on invalid JWT format', function () {
    TokenClaims::fromJwt('not-a-jwt');
})->throws(RuntimeException::class, 'Invalid JWT token format.');

it('throws InvalidTokenClaimsException when required claims are missing', function () {
    TokenClaims::fromArray([
        'sub' => 'user-123',
        // name + phone missing
    ]);
})->throws(
    \Huwiya\Exceptions\InvalidTokenClaimsException::class,
    'missing required keys: name, phone',
);

it('throws InvalidTokenClaimsException when a required claim is empty', function () {
    TokenClaims::fromArray([
        'sub' => 'user-123',
        'name' => '',
        'phone' => '+123',
    ]);
})->throws(
    \Huwiya\Exceptions\InvalidTokenClaimsException::class,
    'missing required keys: name',
);

it('throws on invalid base64 payload', function () {
    TokenClaims::fromJwt('header.!!!invalid!!!.signature');
})->throws(RuntimeException::class, 'Failed to decode token payload.');

it('reports not expired when expiresAt is null', function () {
    $claims = TokenClaims::fromArray([
        'sub' => 'user-123',
        'name' => 'John',
        'phone' => '+123',
    ]);

    expect($claims->isExpired())->toBeFalse();
});

it('reports not expired when token is still valid', function () {
    $claims = TokenClaims::fromArray([
        'sub' => 'user-123',
        'name' => 'John',
        'phone' => '+123',
        'exp' => time() + 3600,
    ]);

    expect($claims->isExpired())->toBeFalse();
});

it('reports expired when token has passed', function () {
    $claims = TokenClaims::fromArray([
        'sub' => 'user-123',
        'name' => 'John',
        'phone' => '+123',
        'exp' => time() - 100,
    ]);

    expect($claims->isExpired())->toBeTrue();
});

it('respects leeway for expiration check', function () {
    $claims = TokenClaims::fromArray([
        'sub' => 'user-123',
        'name' => 'John',
        'phone' => '+123',
        'exp' => time() - 30,
    ]);

    expect($claims->isExpired(leeway: 60))->toBeFalse()
        ->and($claims->isExpired(leeway: 10))->toBeTrue();
});
