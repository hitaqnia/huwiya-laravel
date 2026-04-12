<?php

namespace Huwiya\Support;

use Huwiya\Huwiya;
use Huwiya\TokenClaims;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Thin instance wrapper around the static Huwiya helpers, bound in the
 * container so the Huwiya facade has an accessor target. The static
 * Huwiya class remains the canonical public API; this class only exists
 * to make the facade pattern work.
 */
class HuwiyaManager
{
    public function whenAuthorizationDenied(callable $callback): void
    {
        Huwiya::whenAuthorizationDenied($callback);
    }

    public function denied(?string $error = null, ?string $description = null): mixed
    {
        return Huwiya::denied($error, $description);
    }

    public function decodeAndVerifyToken(string $token): ?TokenClaims
    {
        return Huwiya::decodeAndVerifyToken($token);
    }

    public function getPublicKey(string $kid): string
    {
        return Huwiya::getPublicKey($kid);
    }

    public function base64UrlDecode(string $input): string|false
    {
        return Huwiya::base64UrlDecode($input);
    }

    public function sessionKeyForGuard(string $guard): string
    {
        return Huwiya::sessionKeyForGuard($guard);
    }

    public function jwksCacheKey(): string
    {
        return Huwiya::jwksCacheKey();
    }

    public function actingAs(Authenticatable $user, string $guard = 'web'): Authenticatable
    {
        return Huwiya::actingAs($user, $guard);
    }

    public function flush(): void
    {
        Huwiya::flush();
    }
}
