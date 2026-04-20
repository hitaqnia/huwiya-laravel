<?php

namespace Huwiya\Support;

/**
 * Reason codes for token rejection returned via Result::failure(Error::make(...)).
 *
 * Consumers can branch on these codes to produce precise 4xx responses or
 * metrics without relying on exception types.
 */
final class TokenRejection
{
    public const MALFORMED = 1001;

    public const BAD_SIGNATURE = 1002;

    public const EXPIRED = 1003;

    public const BAD_ISSUER = 1004;

    public const BAD_AUDIENCE = 1005;

    public const MISSING_CLAIMS = 1006;

    public const JWKS_UNAVAILABLE = 1007;

    public const UNKNOWN_KID = 1008;

    public const UNSUPPORTED_KEY_TYPE = 1009;

    private function __construct() {}
}
