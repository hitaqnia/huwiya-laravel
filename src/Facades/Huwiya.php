<?php

namespace Huwiya\Facades;

use Huwiya\Support\HuwiyaManager;
use Huwiya\TokenClaims;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void whenAuthorizationDenied(callable $callback)
 * @method static mixed denied(?string $error = null, ?string $description = null)
 * @method static ?TokenClaims decodeAndVerifyToken(string $token)
 * @method static string getPublicKey(string $kid)
 * @method static string|false base64UrlDecode(string $input)
 * @method static string sessionKeyForGuard(string $guard)
 * @method static string jwksCacheKey()
 * @method static Authenticatable actingAs(Authenticatable $user, string $guard = 'web')
 * @method static void flush()
 *
 * @see \Huwiya\Huwiya
 */
class Huwiya extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HuwiyaManager::class;
    }
}
