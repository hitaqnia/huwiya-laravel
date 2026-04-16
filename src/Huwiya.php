<?php

namespace Huwiya;

use Huwiya\Exceptions\AuthConfigurationException;
use Huwiya\Exceptions\InvalidGuardException;
use Huwiya\Exceptions\InvalidJwtFormatException;
use Huwiya\Exceptions\JwksFetchException;
use Huwiya\Exceptions\UnknownKidException;
use Huwiya\Exceptions\UnsupportedKeyTypeException;
use Huwiya\Support\AuthorizationDeniedCallback;
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
     * alongside the OAuth state in a single session entry, so the callback
     * resolves the guard from the bound session payload rather than config.
     */
    public static function redirect(string $guard): RedirectResponse
    {
        static::assertGuardIsHuwiyaWeb($guard);

        $state = Str::random(40);

        session()->put('huwiya.oauth', [
            'state' => $state,
            'guard' => $guard,
        ]);

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
     * Decode and verify a JWT token, returning claims or null on failure.
     */
    public static function decodeAndVerifyToken(string $token): ?TokenClaims
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            static::log()?->warning('Huwiya: JWT rejected — wrong segment count.', ['category' => 'format']);

            return null;
        }

        $payload = static::base64UrlDecode($parts[1]);

        if ($payload === false) {
            static::log()?->warning('Huwiya: JWT rejected — payload not valid base64url.', ['category' => 'format']);

            return null;
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            static::log()?->warning('Huwiya: JWT rejected — payload not valid JSON.', ['category' => 'format']);

            return null;
        }

        if (config('huwiya.verify_signature', true)) {
            try {
                if (! static::verifySignature($token)) {
                    static::log()?->warning('Huwiya: JWT rejected — signature verification failed.', ['category' => 'signature']);

                    return null;
                }
            } catch (InvalidJwtFormatException|JwksFetchException|UnknownKidException|UnsupportedKeyTypeException $e) {
                static::log()?->warning('Huwiya: JWT rejected — '.$e->getMessage(), [
                    'category' => 'signature',
                    'exception' => $e::class,
                ]);

                return null;
            }
        }

        try {
            $claims = TokenClaims::fromArray($decoded);
        } catch (\Huwiya\Exceptions\InvalidTokenClaimsException $e) {
            static::log()?->warning('Huwiya: JWT rejected — '.$e->getMessage(), ['category' => 'claims']);

            return null;
        }

        if (! static::isTokenValid($claims)) {
            static::log()?->warning('Huwiya: JWT rejected — claim validation failed (expired, issuer, or audience).', [
                'category' => 'claims',
                'issuer' => $claims->issuer,
                'audience' => $claims->audience,
            ]);

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
        Cache::forget(static::jwksCacheKey());

        $pem = static::findKeyInCachedJwks($kid);

        if ($pem === null) {
            throw UnknownKidException::forKid($kid);
        }

        return $pem;
    }

    /**
     * Find a key by kid in the cached JWKS.
     */
    protected static function findKeyInCachedJwks(string $kid): ?string
    {
        $jwks = Cache::remember(static::jwksCacheKey(), 3600, function () {
            return static::fetchJwks();
        });

        if ($jwks === null) {
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
        app(AuthorizationDeniedCallback::class)->reset();
    }

    /**
     * Resolve the configured log channel, or null if logging is not enabled.
     */
    public static function log(): ?LoggerInterface
    {
        $channel = config('huwiya.log_channel');

        if (! is_string($channel) || $channel === '') {
            return null;
        }

        return Log::channel($channel);
    }
}
