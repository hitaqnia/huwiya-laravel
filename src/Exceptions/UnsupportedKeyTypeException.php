<?php

namespace Huwiya\Exceptions;

class UnsupportedKeyTypeException extends HuwiyaException
{
    public static function forKty(string $kid, string $kty): self
    {
        return new self("JWK for kid [{$kid}] has unsupported key type [{$kty}]; only RSA is supported.");
    }
}
