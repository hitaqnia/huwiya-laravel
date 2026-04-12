<?php

namespace Huwiya\Exceptions;

class UnknownKidException extends HuwiyaException
{
    public static function forKid(string $kid): self
    {
        return new self("No key found in JWKS for kid: {$kid}.");
    }
}
