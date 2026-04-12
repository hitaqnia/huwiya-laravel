<?php

namespace Huwiya;

use Huwiya\Exceptions\InvalidJwtFormatException;
use Huwiya\Exceptions\InvalidTokenClaimsException;

class TokenClaims
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $phoneNumber,
        public readonly ?string $issuer = null,
        public readonly ?int $issuedAt = null,
        public readonly ?int $expiresAt = null,
        public readonly ?string $audience = null,
    ) {}

    /**
     * Create a new instance from a decoded JWT payload.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function fromArray(array $claims): self
    {
        $missing = [];

        foreach (['sub', 'name', 'phone'] as $required) {
            if (! array_key_exists($required, $claims) || $claims[$required] === null || $claims[$required] === '') {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw InvalidTokenClaimsException::missingKeys($missing);
        }

        return new self(
            id: (string) $claims['sub'],
            name: (string) $claims['name'],
            phoneNumber: (string) $claims['phone'],
            issuer: isset($claims['iss']) ? (string) $claims['iss'] : null,
            issuedAt: isset($claims['iat']) ? (int) $claims['iat'] : null,
            expiresAt: isset($claims['exp']) ? (int) $claims['exp'] : null,
            audience: isset($claims['aud']) ? (string) $claims['aud'] : null,
        );
    }

    /**
     * Decode JWT payload claims without signature verification.
     *
     * Used for tokens received directly from the IdP token endpoint
     * where the transport is already trusted (server-to-server HTTPS).
     */
    public static function fromJwt(string $token): self
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidJwtFormatException('Invalid JWT token format.');
        }

        $payload = Huwiya::base64UrlDecode($parts[1]);

        if ($payload === false) {
            throw new InvalidJwtFormatException('Failed to decode token payload.');
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new InvalidJwtFormatException('Failed to parse token claims.');
        }

        return self::fromArray($decoded);
    }

    /**
     * Determine if the token has expired.
     */
    public function isExpired(int $leeway = 0): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt + $leeway < time();
    }
}
