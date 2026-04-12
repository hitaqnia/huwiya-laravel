<?php

namespace Huwiya;

use RuntimeException;

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
        return new self(
            id: $claims['sub'],
            name: $claims['name'],
            phoneNumber: $claims['phone'],
            issuer: $claims['iss'] ?? null,
            issuedAt: isset($claims['iat']) ? (int) $claims['iat'] : null,
            expiresAt: isset($claims['exp']) ? (int) $claims['exp'] : null,
            audience: $claims['aud'] ?? null,
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

        throw_unless(count($parts) === 3, RuntimeException::class, 'Invalid JWT token format.');

        $payload = base64_decode(strtr($parts[1], '-_', '+/'));

        throw_unless($payload !== false, RuntimeException::class, 'Failed to decode token payload.');

        $decoded = json_decode($payload, true);

        throw_unless(is_array($decoded), RuntimeException::class, 'Failed to parse token claims.');

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
