<?php

namespace Huwiya;

use Huwiya\Exceptions\AuthConfigurationException;
use Huwiya\Exceptions\InvalidGuardException;
use Huwiya\Exceptions\InvalidJwtFormatException;
use Huwiya\Exceptions\InvalidTokenClaimsException;
use Huwiya\Exceptions\JwksFetchException;
use Huwiya\Exceptions\UnknownKidException;
use Huwiya\Exceptions\UnsupportedKeyTypeException;
use Huwiya\Support\AuthorizationDeniedCallback;
use Huwiya\Support\Error;
use Huwiya\Support\Result;
use Huwiya\Support\TokenRejection;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class Huwiya
{
    /**
     * Cached log channel instance. Cleared via `flush()`.
     */
    protected static ?LoggerInterface $cachedLogger = null;

    /**
     * Sentinel used to mark the cached logger as explicitly "no channel configured".
     */
    protected static bool $cachedLoggerResolved = false;

    /**
     * Register the callback used when the user denies the authorization request.
     *
     * The callback may declare up to two parameters: `?string $error` and
     * `?string $errorDescription`. Zero-arg callbacks continue to work.
     *
     * Storage is container-scoped so long-running workers (Octane, Swoole)
     * reset between requests automatically.
     *
     * @param  callable  $callback
     */
    public static function whenAuthorizationDenied(callable $callback): void
    {
        app(AuthorizationDeniedCallback::class)->set($callback);
    }

    /**
     * Handle authorization denial using the registered callback or a default redirect.
     */
    public static function denied(?string $error = null, ?string $description = null): mixed
    {
        $holder = app(AuthorizationDeniedCallback::class);

        if ($holder->isSet()) {
            return $holder->invoke($error, $description);
        }

        return redirect('/');
    }

    /**
     * Initiate an OAuth authorization redirect bound to a specific web guard.
     *
     * The guard is validated against the auth config and stored atomically
     * alongside the OAuth state (and optional post-login URL) in a single
     * session entry, so the callback resolves everything from the bound
     * session payload rather than config.
     *
     * @throws InvalidGuardException
     * @throws AuthConfigurationException
     */
    public static function redirect(string $guard, ?string $intendedUrl = null): RedirectResponse
    {
        static::assertGuardIsHuwiyaWeb($guard);

        $state = Str::random(40);

        $payload = [
            'state' => $state,
            'guard' => $guard,
        ];

        if ($intendedUrl !== null && $intendedUrl !== '') {
            $payload['intended'] = $intendedUrl;
        }

        session()->put('huwiya.oauth', $payload);

        $query = http_build_query([
            'client_id' => config('huwiya.client_id'),
            'redirect_uri' => config('huwiya.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
        ]);

        return redirect(config('huwiya.url').'/oauth/authorize?'.$query);
    }

    /**
     * Assert that the named guard is registered with the huwiya-web driver
     * and that its provider's model uses the InteractsWithHuwiya trait.
     *
     * Runs at both redirect time (fail fast) and callback time (defense
     * in depth — the guard may have been removed or altered between the
     * two requests).
     */
    public static function assertGuardIsHuwiyaWeb(string $guard): void
    {
        if ($guard === '') {
            throw new InvalidGuardException('A guard name is required.');
        }

        $guardConfig = config("auth.guards.{$guard}");

        if (! is_array($guardConfig)) {
            throw new InvalidGuardException("Auth guard [{$guard}] is not configured.");
        }

        if (($guardConfig['driver'] ?? null) !== 'huwiya-web') {
            throw new InvalidGuardException("Auth guard [{$guard}] must use the [huwiya-web] driver.");
        }

        $provider = $guardConfig['provider'] ?? null;
        $model = $provider ? config("auth.providers.{$provider}.model") : null;

        if (! $model) {
            throw new AuthConfigurationException("Unable to determine user model for guard [{$guard}].");
        }

        if (! in_array(InteractsWithHuwiya::class, class_uses_recursive($model), true)) {
            throw new AuthConfigurationException("The model [{$model}] must use the InteractsWithHuwiya trait.");
        }
    }

    /**
     * Decode and verify a JWT token.
     *
     * Returns a Result wrapping the TokenClaims on success, or a Result::failure
     * carrying an Error with a TokenRejection code on failure. Failure modes
     * (malformed, bad signature, expired, bad issuer/audience, missing claims,
     * JWKS unavailable) are all routine domain outcomes for a token received
     * over HTTP, so they're expressed as Result rather than exceptions.
     *
     * @return Result<TokenClaims>
     */
    public static function decodeAndVerifyToken(string $token): Result
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            static::log()?->warning('Huwiya: JWT rejected — wrong segment count.', ['category' => 'format']);

            return Result::failure(Error::make(TokenRejection::MALFORMED, 'JWT must have three segments.'));
        }

        $payload = static::base64UrlDecode($parts[1]);

        if ($payload === false) {
            static::log()?->warning('Huwiya: JWT rejected — payload not valid base64url.', ['category' => 'format']);

            return Result::failure(Error::make(TokenRejection::MALFORMED, 'JWT payload is not valid base64url.'));
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            static::log()?->warning('Huwiya: JWT rejected — payload not valid JSON.', ['category' => 'format']);

            return Result::failure(Error::make(TokenRejection::MALFORMED, 'JWT payload is not valid JSON.'));
        }

        if (config('huwiya.verify_signature', true)) {
            try {
                if (! static::verifySignature($token)) {
                    static::log()?->warning('Huwiya: JWT rejected — signature verification failed.', ['category' => 'signature']);

                    return Result::failure(Error::make(TokenRejection::BAD_SIGNATURE, 'JWT signature verification failed.'));
                }
            } catch (InvalidJwtFormatException $e) {
                static::log()?->warning('Huwiya: JWT rejected — '.$e->getMessage(), ['category' => 'signature']);

                return Result::failure(Error::make(TokenRejection::MALFORMED, $e->getMessage()));
            } catch (JwksFetchException $e) {
                static::log()?->warning('Huwiya: JWT rejected — '.$e->getMessage(), ['category' => 'signature']);

                return Result::failure(Error::make(TokenRejection::JWKS_UNAVAILABLE, $e->getMessage()));
            } catch (UnknownKidException $e) {
                static::log()?->warning('Huwiya: JWT rejected — '.$e->getMessage(), ['category' => 'signature']);

                return Result::failure(Error::make(TokenRejection::UNKNOWN_KID, $e->getMessage()));
            } catch (UnsupportedKeyTypeException $e) {
                static::log()?->warning('Huwiya: JWT rejected — '.$e->getMessage(), ['category' => 'signature']);

                return Result::failure(Error::make(TokenRejection::UNSUPPORTED_KEY_TYPE, $e->getMessage()));
            }
        }

        try {
            $claims = TokenClaims::fromArray($decoded);
        } catch (InvalidTokenClaimsException $e) {
            static::log()?->warning('Huwiya: JWT rejected — '.$e->getMessage(), ['category' => 'claims']);

            return Result::failure(Error::make(TokenRejection::MISSING_CLAIMS, $e->getMessage()));
        }

        return static::validateClaims($claims);
    }

    /**
     * Null-returning convenience wrapper around decodeAndVerifyToken for callers
     * (like Laravel guards) that treat any rejection the same way.
     */
    public static function tryDecodeAndVerifyToken(string $token): ?TokenClaims
    {
        $result = static::decodeAndVerifyToken($token);

        return $result->isSuccess() ? $result->getData() : null;
    }

    /**
     * Verify the JWT signature using the IdP's public key matched by kid.
     */
    protected static function verifySignature(string $token): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidJwtFormatException('JWT must have three segments.');
        }

        $headerJson = static::base64UrlDecode($parts[0]);

        if ($headerJson === false) {
            throw new InvalidJwtFormatException('JWT header is not valid base64url.');
        }

        $header = json_decode($headerJson, true);

        if (! is_array($header) || empty($header['kid'])) {
            throw new InvalidJwtFormatException('JWT is missing the required kid header.');
        }

        $expectedAlg = config('huwiya.algorithm', 'RS256');

        if (($header['alg'] ?? null) !== $expectedAlg) {
            throw new InvalidJwtFormatException("JWT algorithm must be {$expectedAlg}, got: ".($header['alg'] ?? 'none'));
        }

        $publicKey = static::getPublicKey($header['kid']);

        $signature = static::base64UrlDecode($parts[2]);

        if ($signature === false) {
            throw new InvalidJwtFormatException('JWT signature is not valid base64url.');
        }

        $data = $parts[0].'.'.$parts[1];

        $key = openssl_pkey_get_public($publicKey);

        if ($key === false) {
            throw new InvalidJwtFormatException('Failed to parse the public key for kid: '.$header['kid']);
        }

        return openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Validate claim-level invariants (expiry, issuer, audience).
     *
     * @return Result<TokenClaims>
     */
    protected static function validateClaims(TokenClaims $claims): Result
    {
        if ($claims->isExpired((int) config('huwiya.leeway', 60))) {
            static::log()?->warning('Huwiya: JWT rejected — token expired.', ['category' => 'claims']);

            return Result::failure(Error::make(TokenRejection::EXPIRED, 'Token has expired.'));
        }

        if (config('huwiya.validate_issuer', true)) {
            $expected = config('huwiya.url');

            if ($claims->issuer === null || $claims->issuer !== $expected) {
                static::log()?->warning('Huwiya: JWT rejected — issuer mismatch.', [
                    'category' => 'claims',
                    'expected' => $expected,
                    'got' => $claims->issuer,
                ]);

                return Result::failure(Error::make(TokenRejection::BAD_ISSUER, 'Token issuer does not match the configured IdP URL.'));
            }
        }

        if (config('huwiya.validate_audience', true)) {
            $expected = config('huwiya.project_id');

            if ($claims->audience === null || $claims->audience !== $expected) {
                static::log()?->warning('Huwiya: JWT rejected — audience mismatch.', [
                    'category' => 'claims',
                    'expected' => $expected,
                    'got' => $claims->audience,
                ]);

                return Result::failure(Error::make(TokenRejection::BAD_AUDIENCE, 'Token audience does not match the configured project ID.'));
            }
        }

        return Result::success($claims);
    }

    /**
     * Get the public key for JWT verification by kid.
     *
     * Fetches the JWKS from the IdP and finds the key matching the given kid.
     * Caches the JWKS for 1 hour. If the kid is not found in cache, refetches
     * once under a short cache lock so concurrent requests coalesce into a
     * single IdP round-trip during key rotation.
     *
     * @throws JwksFetchException
     * @throws UnknownKidException
     * @throws UnsupportedKeyTypeException
     */
    public static function getPublicKey(string $kid): string
    {
        $pem = static::findKeyInCachedJwks($kid);

        if ($pem !== null) {
            return $pem;
        }

        $pem = static::refetchAndFindKey($kid);

        if ($pem === null) {
            throw UnknownKidException::forKid($kid);
        }

        return $pem;
    }

    /**
     * Refetch the JWKS under a cache lock and re-scan for the requested kid.
     */
    protected static function refetchAndFindKey(string $kid): ?string
    {
        $cacheKey = static::jwksCacheKey();

        $refetch = function () use ($cacheKey, $kid): ?string {
            Cache::forget($cacheKey);

            return static::findKeyInCachedJwks($kid);
        };

        try {
            $lock = Cache::lock($cacheKey.':lock', 10);
        } catch (\Throwable) {
            return $refetch();
        }

        try {
            return $lock->block(3, function () use ($cacheKey, $kid, $refetch) {
                // Another worker may have just refreshed the cache — check before refetching.
                $pem = static::findKeyInCachedJwks($kid);

                if ($pem !== null) {
                    return $pem;
                }

                return $refetch();
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return $refetch();
        }
    }

    /**
     * Find a key by kid in the cached JWKS.
     *
     * @throws JwksFetchException
     * @throws UnsupportedKeyTypeException
     */
    protected static function findKeyInCachedJwks(string $kid): ?string
    {
        $jwks = Cache::remember(static::jwksCacheKey(), 3600, function () {
            return static::fetchJwks();
        });

        if ($jwks === null) {
            // Don't poison the cache with a null value.
            Cache::forget(static::jwksCacheKey());

            throw new JwksFetchException('Failed to fetch JWKS from the IdP.');
        }

        foreach ($jwks['keys'] as $key) {
            if (($key['kid'] ?? null) !== $kid) {
                continue;
            }

            $kty = $key['kty'] ?? '';

            if ($kty !== 'RSA') {
                throw UnsupportedKeyTypeException::forKty($kid, (string) $kty);
            }

            return static::jwkToPem($key);
        }

        return null;
    }

    /**
     * Build the cache key for the JWKS, derived from the configured JWKS URI.
     */
    public static function jwksCacheKey(): string
    {
        $uri = (string) (config('huwiya.jwks_uri') ?: config('huwiya.url').'/'.config('huwiya.project_id'));

        return 'huwiya:jwks:'.sha1($uri);
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
            static::log()?->warning('Huwiya: JWKS URI is not configured.');

            return null;
        }

        $response = Http::timeout(10)->get($uri);

        if (! $response->successful()) {
            static::log()?->warning('Huwiya: JWKS endpoint returned non-successful status.', [
                'uri' => $uri,
                'status' => $response->status(),
            ]);

            return null;
        }

        $jwks = $response->json();

        if (! isset($jwks['keys']) || ! is_array($jwks['keys'])) {
            static::log()?->warning('Huwiya: JWKS response is missing a `keys` array.', ['uri' => $uri]);

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
        if (! isset($jwk['n'], $jwk['e'])) {
            return null;
        }

        $n = static::base64UrlDecode($jwk['n']);
        $e = static::base64UrlDecode($jwk['e']);

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
     * Strictly decode a base64url-encoded string.
     *
     * @return string|false Decoded bytes on success, false on malformed input.
     */
    public static function base64UrlDecode(string $input): string|false
    {
        return base64_decode(strtr($input, '-_', '+/'), true);
    }

    /**
     * Build the Laravel SessionGuard session key for a given guard name.
     */
    public static function sessionKeyForGuard(string $guard): string
    {
        return 'login_'.$guard.'_'.sha1('Illuminate\Auth\SessionGuard');
    }

    /**
     * Set the current user for the application during tests.
     */
    public static function actingAs(Authenticatable $user, string $guard = 'web'): Authenticatable
    {
        app('auth')->guard($guard)->setUser($user);
        app('auth')->shouldUse($guard);

        return $user;
    }

    public static function flush(): void
    {
        app(AuthorizationDeniedCallback::class)->reset();

        static::$cachedLogger = null;
        static::$cachedLoggerResolved = false;
    }

    /**
     * Forward facade calls (which resolve an instance) onto the static API.
     *
     * Every public method on `Huwiya` is static — this bridge lets the
     * `Huwiya` facade resolve `Huwiya::class` as its accessor without a
     * parallel manager class mirroring every method.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return static::$method(...$arguments);
    }

    /**
     * Resolve the configured log channel, or null if logging is not enabled.
     *
     * The channel is resolved once and cached for the lifetime of the process
     * (cleared via `flush()`). Tests that swap the log channel should call
     * `Huwiya::flush()` between scenarios.
     */
    public static function log(): ?LoggerInterface
    {
        if (static::$cachedLoggerResolved) {
            return static::$cachedLogger;
        }

        $channel = config('huwiya.log_channel');

        static::$cachedLogger = (is_string($channel) && $channel !== '')
            ? Log::channel($channel)
            : null;
        static::$cachedLoggerResolved = true;

        return static::$cachedLogger;
    }
}
