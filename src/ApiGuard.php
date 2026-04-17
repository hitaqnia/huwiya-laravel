<?php

namespace Huwiya;

use Huwiya\Exceptions\AuthConfigurationException;
use Illuminate\Http\Request;

class ApiGuard
{
    public function __construct(
        protected ?string $provider = null,
        protected ?string $guardName = null,
    ) {}

    /**
     * Retrieve the authenticated user for the incoming request.
     *
     * For the API driver, authentication is stateless. The JWT bearer token
     * is decoded, verified, and the user is resolved from the provider model.
     */
    public function __invoke(Request $request): mixed
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        $claims = Huwiya::decodeAndVerifyToken($token);

        if ($claims === null) {
            return null;
        }

        $model = config("auth.providers.{$this->provider}.model");

        if ($model === null) {
            throw new AuthConfigurationException(
                "Unable to determine user model for auth provider [{$this->provider}]. "
                .'Check your config/auth.php providers configuration.'
            );
        }

        if (! in_array(InteractsWithHuwiya::class, class_uses_recursive($model), true)) {
            throw new AuthConfigurationException(
                "The model [{$model}] must use the InteractsWithHuwiya trait."
            );
        }

        $user = $model::findOrCreateFromHuwiya($claims, $this->guardName);

        $user->huwiyaToken = $claims;

        return $user;
    }
}
