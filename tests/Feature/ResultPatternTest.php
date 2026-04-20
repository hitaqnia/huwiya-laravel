<?php

use Huwiya\Huwiya;
use Huwiya\Support\Result;
use Huwiya\Support\TokenRejection;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'huwiya.url' => 'https://idp.example.com',
        'huwiya.project_id' => 'test-project-id',
        'huwiya.verify_signature' => false,
        'huwiya.validate_issuer' => false,
        'huwiya.validate_audience' => false,
        'huwiya.leeway' => 60,
    ]);
});

function makeJwt(array $claims): string
{
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'test-kid'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');

    return "{$header}.{$payload}.sig";
}

it('returns Result::success with TokenClaims for a valid token', function () {
    $jwt = makeJwt([
        'id' => (string) Str::ulid(),
        'name' => 'User',
        'phone' => '+964770',
        'email' => 'u@example.com',
        'scopes' => [],
    ]);

    $result = Huwiya::decodeAndVerifyToken($jwt);

    expect($result)->toBeInstanceOf(Result::class)
        ->and($result->isSuccess())->toBeTrue()
        ->and($result->getData())->toBeInstanceOf(\Huwiya\TokenClaims::class);
});

it('returns Result::failure with MALFORMED code for a wrong-segment JWT', function () {
    $result = Huwiya::decodeAndVerifyToken('not.a.jwt.at.all');

    expect($result->isFailure())->toBeTrue()
        ->and($result->getError()?->getCode())->toBe(TokenRejection::MALFORMED);
});

it('returns Result::failure with MALFORMED for invalid base64 payload', function () {
    $result = Huwiya::decodeAndVerifyToken('header.!!!invalid!!!.sig');

    expect($result->isFailure())->toBeTrue()
        ->and($result->getError()?->getCode())->toBe(TokenRejection::MALFORMED);
});

it('returns Result::failure with MISSING_CLAIMS when identity claims are absent', function () {
    $jwt = makeJwt([
        'id' => (string) Str::ulid(),
        // no name/phone/email/scopes
    ]);

    $result = Huwiya::decodeAndVerifyToken($jwt);

    expect($result->isFailure())->toBeTrue()
        ->and($result->getError()?->getCode())->toBe(TokenRejection::MISSING_CLAIMS);
});

it('returns Result::failure with EXPIRED when token is past expiry', function () {
    $jwt = makeJwt([
        'id' => (string) Str::ulid(),
        'name' => 'User',
        'phone' => '+964770',
        'email' => 'u@example.com',
        'scopes' => [],
        'exp' => time() - 1000,
    ]);

    $result = Huwiya::decodeAndVerifyToken($jwt);

    expect($result->isFailure())->toBeTrue()
        ->and($result->getError()?->getCode())->toBe(TokenRejection::EXPIRED);
});

it('returns Result::failure with BAD_ISSUER when issuer validation is on and issuer mismatches', function () {
    config(['huwiya.validate_issuer' => true]);

    $jwt = makeJwt([
        'id' => (string) Str::ulid(),
        'name' => 'User',
        'phone' => '+964770',
        'email' => 'u@example.com',
        'scopes' => [],
        'iss' => 'https://evil.example.com',
    ]);

    $result = Huwiya::decodeAndVerifyToken($jwt);

    expect($result->isFailure())->toBeTrue()
        ->and($result->getError()?->getCode())->toBe(TokenRejection::BAD_ISSUER);
});

it('returns Result::failure with BAD_AUDIENCE when audience validation is on and audience mismatches', function () {
    config(['huwiya.validate_audience' => true]);

    $jwt = makeJwt([
        'id' => (string) Str::ulid(),
        'name' => 'User',
        'phone' => '+964770',
        'email' => 'u@example.com',
        'scopes' => [],
        'aud' => 'wrong-project',
    ]);

    $result = Huwiya::decodeAndVerifyToken($jwt);

    expect($result->isFailure())->toBeTrue()
        ->and($result->getError()?->getCode())->toBe(TokenRejection::BAD_AUDIENCE);
});

it('tryDecodeAndVerifyToken returns null on failure and TokenClaims on success', function () {
    $valid = makeJwt([
        'id' => (string) Str::ulid(),
        'name' => 'User',
        'phone' => '+964770',
        'email' => 'u@example.com',
        'scopes' => [],
    ]);

    expect(Huwiya::tryDecodeAndVerifyToken($valid))->toBeInstanceOf(\Huwiya\TokenClaims::class);
    expect(Huwiya::tryDecodeAndVerifyToken('malformed'))->toBeNull();
});
