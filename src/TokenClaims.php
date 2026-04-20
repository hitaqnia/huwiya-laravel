<?php

namespace Huwiya;

use Huwiya\Exceptions\InvalidJwtFormatException;
use Huwiya\Exceptions\InvalidTokenClaimsException;
use Illuminate\Support\Str;

class TokenClaims
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $phone,
        public readonly string $email,
        public readonly string $locale = '',
        public readonly string $zoneinfo = '',
        public readonly string $theme = '',
        public readonly array $scopes = [],
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

        // Identity fields required — SDK contract. Preference fields (locale,
        // zoneinfo, theme) are optional; absent values default to empty string.
        foreach (['id', 'name', 'phone', 'email'] as $required) {
            if (! array_key_exists($required, $claims) || $claims[$required] === null || $claims[$required] === '') {
                $missing[] = $required;
            }
        }

        if (! array_key_exists('scopes', $claims) || ! is_array($claims['scopes'])) {
            $missing[] = 'scopes';
        }

        if ($missing !== []) {
            throw InvalidTokenClaimsException::missingKeys($missing);
        }

        $id = (string) $claims['id'];

        if (! Str::isUlid($id)) {
            throw InvalidTokenClaimsException::invalidUlid($id);
        }

        return new self(
            id: $id,
            name: (string) $claims['name'],
            phone: (string) $claims['phone'],
            email: (string) $claims['email'],
            locale: isset($claims['locale']) ? (string) $claims['locale'] : '',
            zoneinfo: isset($claims['zoneinfo']) ? (string) $claims['zoneinfo'] : '',
            theme: isset($claims['theme']) ? (string) $claims['theme'] : '',
            scopes: array_values(array_map('strval', $claims['scopes'])),
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
