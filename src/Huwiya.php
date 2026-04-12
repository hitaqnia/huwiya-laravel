<?php

namespace Huwiya;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Huwiya
{
    /** @var (callable(): mixed)|null */
    protected static $authorizationDeniedCallback = null;

    /**
     * Register the callback used when the user denies the authorization request.
     *
     * @param  callable(): mixed  $callback
     */
    public static function whenAuthorizationDenied(callable $callback): void
    {
        static::$authorizationDeniedCallback = $callback;
    }

    /**
     * Handle authorization denial using the registered callback or a default redirect.
     */
    public static function denied(): mixed
    {
        if (static::$authorizationDeniedCallback !== null) {
            return call_user_func(static::$authorizationDeniedCallback);
        }

        return redirect('/');
    }

    /**
     * Decode and verify a JWT token, returning claims or null on failure.
     */
    public static function decodeAndVerifyToken(string $token): ?TokenClaims
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'));

        if ($payload === false) {
            return null;
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        if (config('huwiya.verify_signature', true)) {
            try {
                if (! static::verifySignature($token)) {
                    return null;
                }
            } catch (RuntimeException) {
                return null;
            }
        }

        $claims = TokenClaims::fromArray($decoded);

        if (! static::isTokenValid($claims)) {
            return null;
        }

        return $claims;
    }

    /**
     * Verify the JWT signature using the IdP's public key matched by kid.
     */
    protected static function verifySignature(string $token): bool
    {
        $parts = explode('.', $token);

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);

        if (! is_array($header) || empty($header['kid'])) {
            throw new RuntimeException('JWT is missing the required kid header.');
        }

        $expectedAlg = config('huwiya.algorithm', 'RS256');

        if (($header['alg'] ?? null) !== $expectedAlg) {
            throw new RuntimeException("JWT algorithm must be {$expectedAlg}, got: ".($header['alg'] ?? 'none'));
        }

        $publicKey = static::getPublicKey($header['kid']);

        $signature = base64_decode(strtr($parts[2], '-_', '+/'));
        $data = $parts[0].'.'.$parts[1];

        $key = openssl_pkey_get_public($publicKey);

        if ($key === false) {
            throw new RuntimeException('Failed to parse the public key for kid: '.$header['kid']);
        }

        return openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Check if the token claims are valid (expiration, issuer, audience).
     */
    protected static function isTokenValid(TokenClaims $claims): bool
    {
        if ($claims->isExpired((int) config('huwiya.leeway', 60))) {
            return false;
        }

        if (config('huwiya.validate_issuer', true) && $claims->issuer !== config('huwiya.url')) {
            return false;
        }

        if (config('huwiya.validate_audience', true) && $claims->audience !== config('huwiya.project_id')) {
            return false;
        }

        return true;
    }

    /**
     * Get the public key for JWT verification by kid.
     *
     * Fetches the JWKS from the IdP and finds the key matching the given kid.
     * Caches the JWKS for 1 hour. If the kid is not found in cache, busts
     * the cache and refetches once to handle key rotation.
     */
    public static function getPublicKey(string $kid): string
    {
        $pem = static::findKeyInCachedJwks($kid);

        if ($pem !== null) {
            return $pem;
        }

        // Kid not found in cache — bust cache and refetch (key rotation).
        Cache::forget('huwiya:jwks');

        $pem = static::findKeyInCachedJwks($kid);

        if ($pem === null) {
            throw new RuntimeException("No key found in JWKS for kid: {$kid}");
        }

        return $pem;
    }

    /**
     * Find a key by kid in the cached JWKS.
     */
    protected static function findKeyInCachedJwks(string $kid): ?string
    {
        $jwks = Cache::remember('huwiya:jwks', 3600, function () {
            return static::fetchJwks();
        });

        if ($jwks === null) {
            throw new RuntimeException('Failed to fetch JWKS from the IdP.');
        }

        foreach ($jwks['keys'] as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return static::jwkToPem($key);
            }
        }

        return null;
    }

    /**
     * Fetch the JWKS from the IdP.
     *
     * @return array<string, mixed>|null
     */
    protected static function fetchJwks(): ?array
    {
        $uri = config('huwiya.jwks_uri') ?: config('huwiya.url').'/'.config('huwiya.project_id').'/.well-known/jwks.json';

        if (! $uri) {
            return null;
        }

        $response = Http::timeout(10)->get($uri);

        if (! $response->successful()) {
            return null;
        }

        $jwks = $response->json();

        if (! isset($jwks['keys']) || ! is_array($jwks['keys'])) {
            return null;
        }

        return $jwks;
    }

    /**
     * Convert a JWK RSA key to PEM format.
     *
     * @param  array<string, string>  $jwk
     */
    protected static function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) !== 'RSA') {
            return null;
        }

        $n = base64_decode(strtr($jwk['n'], '-_', '+/'));
        $e = base64_decode(strtr($jwk['e'], '-_', '+/'));

        if ($n === false || $e === false) {
            return null;
        }

        // Build DER-encoded RSA public key
        $modulus = chr(0).$n;
        $modulus = chr(2).static::derLength(strlen($modulus)).$modulus;
        $exponent = chr(2).static::derLength(strlen($e)).$e;

        $rsaPublicKey = chr(0x30).static::derLength(strlen($modulus.$exponent)).$modulus.$exponent;

        $rsaOid = pack('H*', '300d06092a864886f70d0101010500');
        $bitString = chr(0).$rsaPublicKey;
        $bitString = chr(3).static::derLength(strlen($bitString)).$bitString;

        $der = chr(0x30).static::derLength(strlen($rsaOid.$bitString)).$rsaOid.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n".
            chunk_split(base64_encode($der), 64, "\n").
            "-----END PUBLIC KEY-----\n";
    }

    /**
     * Encode a DER length value.
     */
    protected static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $temp = ltrim(pack('N', $length), chr(0));

        return chr(0x80 | strlen($temp)).$temp;
    }

    /**
     * Get the current application URL with port for stateful domain config.
     */
    public static function currentApplicationUrlWithPort(): string
    {
        $appUrl = config('app.url');

        if (! $appUrl) {
            return '';
        }

        $host = parse_url($appUrl, PHP_URL_HOST);
        $port = parse_url($appUrl, PHP_URL_PORT);

        return ','.$host.($port ? ':'.$port : '');
    }

    /**
     * Set the current user for the application during tests.
     *
     * @param  Authenticatable  $user
     * @return Authenticatable
     */
    public static function actingAs($user, string $guard = 'web')
    {
        app('auth')->guard($guard)->setUser($user);
        app('auth')->shouldUse($guard);

        return $user;
    }

    public static function flush(): void
    {
        static::$authorizationDeniedCallback = null;
    }
}
