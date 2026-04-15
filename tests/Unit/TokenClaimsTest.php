<?php

use Huwiya\TokenClaims;
use Illuminate\Support\Str;

function validUlid(): string
{
    return (string) Str::ulid();
}

it('creates claims from array', function () {
    $id = validUlid();

    $claims = TokenClaims::fromArray([
        'id' => $id,
        'name' => 'John Doe',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => ['profile', 'email'],
        'iss' => 'https://idp.example.com',
        'iat' => 1000000,
        'exp' => 2000000,
        'aud' => 'my-app',
    ]);

    expect($claims->id)->toBe($id)
        ->and($claims->name)->toBe('John Doe')
        ->and($claims->locale)->toBe('en')
        ->and($claims->zoneinfo)->toBe('Asia/Baghdad')
        ->and($claims->theme)->toBe('light')
        ->and($claims->scopes)->toBe(['profile', 'email'])
        ->and($claims->issuer)->toBe('https://idp.example.com')
        ->and($claims->issuedAt)->toBe(1000000)
        ->and($claims->expiresAt)->toBe(2000000)
        ->and($claims->audience)->toBe('my-app');
});

it('accepts an empty scopes array', function () {
    $claims = TokenClaims::fromArray([
        'id' => validUlid(),
        'name' => 'John',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => [],
    ]);

    expect($claims->scopes)->toBe([]);
});

it('creates claims with optional jwt fields defaulting to null', function () {
    $claims = TokenClaims::fromArray([
        'id' => validUlid(),
        'name' => 'John',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => [],
    ]);

    expect($claims->issuer)->toBeNull()
        ->and($claims->issuedAt)->toBeNull()
        ->and($claims->expiresAt)->toBeNull()
        ->and($claims->audience)->toBeNull();
});

it('decodes claims from a JWT string', function () {
    $id = validUlid();
    $payload = base64_encode(json_encode([
        'id' => $id,
        'name' => 'Jane Doe',
        'locale' => 'ar',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'dark',
        'scopes' => ['profile'],
        'exp' => 9999999999,
    ]));

    $token = "eyJhbGciOiJSUzI1NiJ9.{$payload}.fake-signature";

    $claims = TokenClaims::fromJwt($token);

    expect($claims->id)->toBe($id)
        ->and($claims->name)->toBe('Jane Doe')
        ->and($claims->locale)->toBe('ar')
        ->and($claims->theme)->toBe('dark');
});

it('throws on invalid JWT format', function () {
    TokenClaims::fromJwt('not-a-jwt');
})->throws(RuntimeException::class, 'Invalid JWT token format.');

it('throws InvalidTokenClaimsException when required claims are missing', function () {
    TokenClaims::fromArray([
        'id' => validUlid(),
        // everything else missing
    ]);
})->throws(
    \Huwiya\Exceptions\InvalidTokenClaimsException::class,
    'missing required keys: name, locale, zoneinfo, theme, scopes',
);

it('throws InvalidTokenClaimsException when a required claim is empty', function () {
    TokenClaims::fromArray([
        'id' => validUlid(),
        'name' => '',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => [],
    ]);
})->throws(
    \Huwiya\Exceptions\InvalidTokenClaimsException::class,
    'missing required keys: name',
);

it('throws InvalidTokenClaimsException when scopes is not an array', function () {
    TokenClaims::fromArray([
        'id' => validUlid(),
        'name' => 'John',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => 'profile',
    ]);
})->throws(
    \Huwiya\Exceptions\InvalidTokenClaimsException::class,
    'missing required keys: scopes',
);

it('throws InvalidTokenClaimsException when id is not a valid ULID', function () {
    TokenClaims::fromArray([
        'id' => 'not-a-ulid',
        'name' => 'John',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => [],
    ]);
})->throws(
    \Huwiya\Exceptions\InvalidTokenClaimsException::class,
    'is not a valid ULID',
);

it('throws on invalid base64 payload', function () {
    TokenClaims::fromJwt('header.!!!invalid!!!.signature');
})->throws(RuntimeException::class, 'Failed to decode token payload.');

it('reports not expired when expiresAt is null', function () {
    $claims = TokenClaims::fromArray([
        'id' => validUlid(),
        'name' => 'John',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => [],
    ]);

    expect($claims->isExpired())->toBeFalse();
});

it('reports not expired when token is still valid', function () {
    $claims = TokenClaims::fromArray([
        'id' => validUlid(),
        'name' => 'John',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => [],
        'exp' => time() + 3600,
    ]);

    expect($claims->isExpired())->toBeFalse();
});

it('reports expired when token has passed', function () {
    $claims = TokenClaims::fromArray([
        'id' => validUlid(),
        'name' => 'John',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => [],
        'exp' => time() - 100,
    ]);

    expect($claims->isExpired())->toBeTrue();
});

it('respects leeway for expiration check', function () {
    $claims = TokenClaims::fromArray([
        'id' => validUlid(),
        'name' => 'John',
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => [],
        'exp' => time() - 30,
    ]);

    expect($claims->isExpired(leeway: 60))->toBeFalse()
        ->and($claims->isExpired(leeway: 10))->toBeTrue();
});
