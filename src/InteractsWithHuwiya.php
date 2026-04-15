<?php

namespace Huwiya;

use Huwiya\Exceptions\HuwiyaUserNotFoundException;

trait InteractsWithHuwiya
{
    /**
     * The current Huwiya token claims for the authenticated user.
     */
    public ?TokenClaims $huwiyaToken = null;

    /**
     * Ensure the Huwiya identifier column is mass-assignable without
     * clobbering the developer's own $fillable / $guarded configuration.
     */
    public function initializeInteractsWithHuwiya(): void
    {
        if ($this->totallyGuarded()) {
            return;
        }

        $column = $this->getHuwiyaIdentifierColumn();

        if (in_array($column, $this->getFillable(), true)) {
            return;
        }

        $this->mergeFillable([$column]);
    }

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
        ];
    }

    /**
     * Find or create a user from Huwiya token claims.
     */
    public static function findOrCreateFromHuwiya(TokenClaims $claims): static
    {
        $instance = new static;
        $identifier = $instance->getHuwiyaIdentifierColumn();

        if (! $instance->shouldAutoRegister()) {
            $user = static::where($identifier, $claims->id)->first();

            if ($user === null) {
                throw new HuwiyaUserNotFoundException('User not found and auto-registration is disabled.');
            }

            $updateAttributes = $user->getHuwiyaUpdateAttributes($claims);

            if ($updateAttributes !== []) {
                $user->update($updateAttributes);
            }

            return $user;
        }

        $user = static::firstOrCreate(
            [$identifier => $claims->id],
            $instance->getHuwiyaCreateAttributes($claims),
        );

        if (! $user->wasRecentlyCreated) {
            $updateAttributes = $user->getHuwiyaUpdateAttributes($claims);

            if ($updateAttributes !== []) {
                $user->update($updateAttributes);
            }
        }

        return $user;
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
