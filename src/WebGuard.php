<?php

namespace Huwiya;

use Illuminate\Auth\SessionGuard;

class WebGuard extends SessionGuard
{
    /**
     * Get a unique identifier for the auth session value.
     *
     * Overridden so the session key is derived from SessionGuard's class name
     * rather than WebGuard's, keeping the key format stable for consumers that
     * compute it via {@see Huwiya::sessionKeyForGuard()}.
     */
    public function getName()
    {
        return 'login_'.$this->name.'_'.sha1(SessionGuard::class);
    }

    /**
     * Get the name of the cookie used to store the "recaller".
     */
    public function getRecallerName()
    {
        return 'remember_'.$this->name.'_'.sha1(SessionGuard::class);
    }
}
