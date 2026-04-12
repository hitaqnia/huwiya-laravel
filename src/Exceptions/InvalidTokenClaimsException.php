<?php

namespace Huwiya\Exceptions;

class InvalidTokenClaimsException extends HuwiyaException
{
    /**
     * @param  list<string>  $missing
     */
    public static function missingKeys(array $missing): self
    {
        return new self('Token claims are missing required keys: '.implode(', ', $missing).'.');
    }
}
