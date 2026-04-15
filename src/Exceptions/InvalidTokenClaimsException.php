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

    public static function invalidUlid(string $id): self
    {
        return new self('Token claim "id" is not a valid ULID: '.$id.'.');
    }
}
