<?php

use Huwiya\Tests\TestCase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Generate an RSA key pair for testing JWT operations.
 *
 * @return array{private: OpenSSLAsymmetricKey, public: string}
 */
function getTestRsaKeys(): array
{
    static $keys = null;

    if ($keys === null) {
        $private = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $details = openssl_pkey_get_details($private);

        $keys = [
            'private' => $private,
            'public' => $details['key'],
        ];
    }

    return $keys;
}

/**
 * Create a signed JWT token for testing.
 *
 * @param  array<string, mixed>  $claims
 * @param  array<string, mixed>  $headerOverrides
 */
function createTestJwt(
    array $claims,
    ?OpenSSLAsymmetricKey $privateKey = null,
    array $headerOverrides = [],
): string {
    $keys = getTestRsaKeys();
    $privateKey ??= $keys['private'];

    $headerData = array_merge([
        'alg' => 'RS256',
        'typ' => 'JWT',
        'kid' => 'test-kid',
    ], $headerOverrides);

    $header = rtrim(strtr(base64_encode(json_encode($headerData)), '+/', '-_'), '=');

    $defaults = [
        'iat' => time(),
        'exp' => time() + 3600,
        'locale' => 'en',
        'zoneinfo' => 'Asia/Baghdad',
        'theme' => 'light',
        'scopes' => [],
    ];

    $payload = rtrim(strtr(base64_encode(json_encode(
        array_merge($defaults, $claims)
    )), '+/', '-_'), '=');

    $data = $header.'.'.$payload;

    openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    $encodedSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    return $data.'.'.$encodedSignature;
}

/**
 * Seed the JWKS cache with the test public key so that kid lookup works.
 */
function seedTestJwksCache(): void
{
    $keys = getTestRsaKeys();
    $details = openssl_pkey_get_details(openssl_pkey_get_public($keys['public']));

    Cache::put(\Huwiya\Huwiya::jwksCacheKey(), [
        'keys' => [
            [
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => 'test-kid',
                'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
                'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
            ],
        ],
    ], 3600);
}
