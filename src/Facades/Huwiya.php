<?php

namespace Huwiya\Facades;

use Huwiya\Huwiya as HuwiyaService;
use Huwiya\Support\Result;
use Huwiya\TokenClaims;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void whenAuthorizationDenied(callable $callback)
 * @method static mixed denied(?string $error = null, ?string $description = null)
 * @method static \Illuminate\Http\RedirectResponse redirect(string $guard, ?string $intendedUrl = null)
 * @method static void assertGuardIsHuwiyaWeb(string $guard)
 * @method static Result decodeAndVerifyToken(string $token)
 * @method static ?TokenClaims tryDecodeAndVerifyToken(string $token)
 * @method static string getPublicKey(string $kid)
 * @method static string|false base64UrlDecode(string $input)
 * @method static string sessionKeyForGuard(string $guard)
 * @method static string jwksCacheKey()
 * @method static Authenticatable actingAs(Authenticatable $user, string $guard = 'web')
 * @method static void flush()
 * @method static ?\Psr\Log\LoggerInterface log()
 *
 * @see \Huwiya\Huwiya
 */
class Huwiya extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HuwiyaService::class;
    }
}
