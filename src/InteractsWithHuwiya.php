<?php

namespace Huwiya;

use Huwiya\Events\HuwiyaAuthenticated;
use Huwiya\Events\HuwiyaAuthenticating;
use Huwiya\Events\HuwiyaUserCreated;
use Huwiya\Events\HuwiyaUserCreating;
use Huwiya\Events\HuwiyaUserResolving;
use Huwiya\Events\HuwiyaUserUpdated;
use Huwiya\Events\HuwiyaUserUpdating;
use Huwiya\Exceptions\HuwiyaUserNotFoundException;
use Illuminate\Database\Eloquent\Builder;

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

        // If the model uses the default guarded=[] / fillable=[] posture, it
        // already accepts every attribute — adding to $fillable would flip it
        // into whitelist mode and start rejecting other columns.
        if ($this->getFillable() === []) {
            return;
        }

        $column = $this->getHuwiyaIdentifierColumn();

        if (in_array($column, $this->getFillable(), true)) {
            return;
        }

        $this->mergeFillable([$column]);
    }

    // -------------------------------------------------------------------------
    //  Identifier & Gate
    // -------------------------------------------------------------------------

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
    public function shouldAutoRegister(?TokenClaims $claims = null): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------
    //  Lookup
    // -------------------------------------------------------------------------

    /**
     * Create a new base query for Huwiya user resolution.
     *
     * Override to apply tenant scopes, include soft-deleted records,
     * or eager-load relationships.
     */
    public static function newHuwiyaQuery(): Builder
    {
        return static::query();
    }

    /**
     * Constrain the query to match a user from the given claims.
     *
     * Override to match by multiple columns — for example, to add a
     * phone-fallback for invitation claiming, or to look up by email
     * when the subject ID is not yet known.
     */
    public function huwiyaQueryForClaims(Builder $query, TokenClaims $claims): Builder
    {
        return $query->where($this->getHuwiyaIdentifierColumn(), $claims->id);
    }

    /**
     * Resolve an existing user from the given claims.
     */
    public static function resolveHuwiyaUser(TokenClaims $claims, ?string $guard = null): ?static
    {
        $instance = new static;
        $query = $instance->huwiyaQueryForClaims(static::newHuwiyaQuery(), $claims);

        event(new HuwiyaUserResolving($claims, $query, $guard));

        return $query->first();
    }

    // -------------------------------------------------------------------------
    //  Creation
    // -------------------------------------------------------------------------

    /**
     * Get the attributes to fill when creating a new user from Huwiya claims.
     *
     * Override to sync additional claim fields to your users table.
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
     * Create a new user from the given Huwiya claims.
     *
     * Override to assign roles, attach to a tenant, wrap in a transaction,
     * or perform any custom provisioning logic.
     */
    public static function createHuwiyaUser(TokenClaims $claims): static
    {
        $instance = new static;

        return static::create(array_merge(
            [$instance->getHuwiyaIdentifierColumn() => $claims->id],
            $instance->getHuwiyaCreateAttributes($claims),
        ));
    }

    // -------------------------------------------------------------------------
    //  Update
    // -------------------------------------------------------------------------

    /**
     * Get the attributes to update on an existing user from Huwiya claims.
     *
     * Override to sync additional claim fields, or to include the identifier
     * column when claiming an invitation row (see README "Invitations").
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
     * Update the existing user with data from the given Huwiya claims.
     *
     * Override to sync roles from scopes, conditionally skip updates,
     * or perform any custom update logic.
     */
    public function updateHuwiyaUser(TokenClaims $claims): void
    {
        $attributes = $this->getHuwiyaUpdateAttributes($claims);

        if ($attributes !== []) {
            $this->update($attributes);
        }
    }

    // -------------------------------------------------------------------------
    //  Orchestration
    // -------------------------------------------------------------------------

    /**
     * Find or create a user from Huwiya token claims.
     *
     * @throws HuwiyaUserNotFoundException
     */
    public static function findOrCreateFromHuwiya(TokenClaims $claims, ?string $guard = null): static
    {
        event(new HuwiyaAuthenticating($claims, $guard));

        $user = static::resolveHuwiyaUser($claims, $guard);

        if ($user === null) {
            $instance = new static;

            if (! $instance->shouldAutoRegister($claims)) {
                throw new HuwiyaUserNotFoundException(
                    'User not found and auto-registration is disabled.'
                );
            }

            event(new HuwiyaUserCreating($claims, $guard));

            $user = static::createHuwiyaUser($claims);

            event(new HuwiyaUserCreated($claims, $user, $guard));
        } else {
            event(new HuwiyaUserUpdating($claims, $user, $guard));

            $user->updateHuwiyaUser($claims);

            event(new HuwiyaUserUpdated($claims, $user, $guard));
        }

        event(new HuwiyaAuthenticated($claims, $user, $guard));

        return $user;
    }

    /**
     * Find a user by Huwiya subject identifier.
     */
    public static function findByHuwiyaId(string $id): ?static
    {
        $instance = new static;

        return static::newHuwiyaQuery()
            ->where($instance->getHuwiyaIdentifierColumn(), $id)
            ->first();
    }
}
