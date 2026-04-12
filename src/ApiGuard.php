<?php

namespace Hawia;

use Illuminate\Http\Request;

class ApiGuard
{
    public function __construct(
        protected ?string $provider = null,
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

        $claims = Hawia::decodeAndVerifyToken($token);

        if ($claims === null) {
            return null;
        }

        $model = config("auth.providers.{$this->provider}.model");

        if ($model === null) {
            return null;
        }

        if (! in_array(HasHawiaTokens::class, class_uses_recursive($model))) {
            return null;
        }

        $user = $model::findOrCreateFromHawia($claims);

        $user->hawiaToken = $claims;

        return $user;
    }
}
