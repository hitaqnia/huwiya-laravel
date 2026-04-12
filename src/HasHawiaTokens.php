<?php

namespace Hawia;

trait HasHawiaTokens
{
    /**
     * The current Hawia token claims for the authenticated user.
     */
    public ?TokenClaims $hawiaToken = null;

    /**
     * Get the column name that stores the Hawia subject identifier.
     */
    public function getHawiaIdentifierColumn(): string
    {
        return 'hawia_id';
    }

    /**
     * Determine if new users should be auto-registered on first login.
     */
    public function shouldAutoRegister(): bool
    {
        return true;
    }

    /**
     * Get the attributes to fill when creating a new user from Hawia claims.
     *
     * @return array<string, mixed>
     */
    public function getHawiaCreateAttributes(TokenClaims $claims): array
    {
        return [
            'name' => $claims->name,
            'phone' => $claims->phoneNumber,
        ];
    }

    /**
     * Get the attributes to update on an existing user from Hawia claims.
     *
     * @return array<string, mixed>
     */
    public function getHawiaUpdateAttributes(TokenClaims $claims): array
    {
        return [
            'name' => $claims->name,
            'phone' => $claims->phoneNumber,
        ];
    }

    /**
     * Find or create a user from Hawia token claims.
     */
    public static function findOrCreateFromHawia(TokenClaims $claims): static
    {
        $instance = new static;
        $identifier = $instance->getHawiaIdentifierColumn();

        $user = static::where($identifier, $claims->id)->first();

        if ($user !== null) {
            $updateAttributes = $user->getHawiaUpdateAttributes($claims);

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
            ...$instance->getHawiaCreateAttributes($claims),
        ]);
    }

    /**
     * Find a user by Hawia subject identifier.
     */
    public static function findByHawiaId(string $id): ?static
    {
        $instance = new static;

        return static::where($instance->getHawiaIdentifierColumn(), $id)->first();
    }
}
