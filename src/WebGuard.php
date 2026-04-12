<?php

namespace Huwiya;

use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

class WebGuard
{
    public function __construct(
        protected ?UserProvider $provider = null,
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

        $id = $request->session()->get('login_web_'.sha1('Illuminate\Auth\SessionGuard'));

        if ($id === null) {
            return null;
        }

        return $this->provider->retrieveById($id);
    }
}
