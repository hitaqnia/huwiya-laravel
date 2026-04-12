<?php

namespace Huwiya;

trait HasHuwiyaTokens
{
    /**
     * The current Huwiya token claims for the authenticated user.
     */
    public ?TokenClaims $huwiyaToken = null;

    /**
     * Get the column name that stores the Huwiya subject identifier.
     */
    public function getHuwiyaIdentifierColumn(): string
    {
        return 'huwiya_id';
    }

    /**
     * Determine if new users should be auto-registered on first login.
     */
    public function shouldAutoRegister(): bool
    {
        return true;
    }

    /**
     * Get the attributes to fill when creating a new user from Huwiya claims.
     *
     * @return array<string, mixed>
     */
    public function getHuwiyaCreateAttributes(TokenClaims $claims): array
    {
        return [
            'name' => $claims->name,
            'phone' => $claims->phoneNumber,
        ];
    }

    /**
     * Get the attributes to update on an existing user from Huwiya claims.
     *
     * @return array<string, mixed>
     */
    public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
    {
        return [
            'name' => $claims->name,
            'phone' => $claims->phoneNumber,
        ];
    }

    /**
     * Find or create a user from Huwiya token claims.
     */
    public static function findOrCreateFromHuwiya(TokenClaims $claims): static
    {
        $instance = new static;
        $identifier = $instance->getHuwiyaIdentifierColumn();

        $user = static::where($identifier, $claims->id)->first();

        if ($user !== null) {
            $updateAttributes = $user->getHuwiyaUpdateAttributes($claims);

            if ($updateAttributes !== []) {
                $user->update($updateAttributes);
            }

            return $user;
        }

        if (! $instance->shouldAutoRegister()) {
            throw new \RuntimeException('User not found and auto-registration is disabled.');
        }

        return static::create([
            $identifier => $claims->id,
            ...$instance->getHuwiyaCreateAttributes($claims),
        ]);
    }

    /**
     * Find a user by Huwiya subject identifier.
     */
    public static function findByHuwiyaId(string $id): ?static
    {
        $instance = new static;

        return static::where($instance->getHuwiyaIdentifierColumn(), $id)->first();
    }
}
