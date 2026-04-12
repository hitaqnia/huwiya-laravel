<?php

namespace Huwiya;

use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

class WebGuard
{
    public function __construct(
        protected ?UserProvider $provider = null,
        protected ?string $guardName = null,
    ) {}

    /**
     * Retrieve the authenticated user for the incoming request.
     *
     * For the web driver, authentication is session-based. The user is logged
     * in via the OAuth2 callback flow and subsequent requests are authenticated
     * through the session directly.
     */
    public function __invoke(Request $request): mixed
    {
        if ($this->provider === null) {
            return null;
        }

        if (! $request->hasSession()) {
            return null;
        }

        $guard = $this->guardName ?? (string) config('huwiya.web_guard', 'web');

        $id = $request->session()->get(Huwiya::sessionKeyForGuard($guard));

        if ($id === null) {
            return null;
        }

        return $this->provider->retrieveById($id);
    }
}
