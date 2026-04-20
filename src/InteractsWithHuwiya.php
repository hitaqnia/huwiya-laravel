<?php

namespace Huwiya;

use Huwiya\Events\HuwiyaAuthenticated;
use Huwiya\Events\HuwiyaAuthenticating;
use Huwiya\Events\HuwiyaUserCreated;
use Huwiya\Events\HuwiyaUserCreating;
use Huwiya\Events\HuwiyaUserResolving;
use Huwiya\Events\HuwiyaUserUpdated;
use Huwiya\Events\HuwiyaUserUpdating;
use Huwiya\Exceptions\HuwiyaConflictException;
use Huwiya\Exceptions\HuwiyaUserNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

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

        // If the model uses guarded=[] / fillable=[], it already accepts
        // every attribute — adding to $fillable would flip it into whitelist
        // mode and start rejecting other columns.
        if ($this->getFillable() === []) {
            return;
        }

        $column = $this->getHuwiyaIdentifierColumn();

        if (in_array($column, $this->getFillable(), true)) {
            return;
        }

        $this->mergeFillable([$column]);
    }

    // =========================================================================
    //  POLICY HOOKS — override these on your model to customize behavior.
    //  Each method is single-purpose and safe to override.
    // =========================================================================

    /**
     * The column that stores the Huwiya subject identifier (ULID).
     */
    public function getHuwiyaIdentifierColumn(): string
    {
        return 'huwiya_id';
    }

    /**
     * Accept unknown users? Return false for invite-only installs.
     */
    public function shouldAutoRegister(?TokenClaims $claims = null): bool
    {
        return true;
    }

    /**
     * Base Eloquent query for user resolution. Override to apply tenant
     * scopes, include soft-deleted rows, or eager-load relationships.
     */
    public static function newHuwiyaQuery(): Builder
    {
        return static::query();
    }

    /**
     * Constrain the query to match a user from the given claims. Override to
     * add fallback lookup columns (phone, email) or multi-tenant filters.
     */
    public function huwiyaQueryForClaims(Builder $query, TokenClaims $claims): Builder
    {
        return $query->where($this->getHuwiyaIdentifierColumn(), $claims->id);
    }

    /**
     * Attributes to persist when creating a new user from claims.
     * Default is an empty array — apps opt in to which columns they sync.
     *
     * @return array<string, mixed>
     */
    public function getHuwiyaCreateAttributes(TokenClaims $claims): array
    {
        return [];
    }

    /**
     * Attributes to persist when updating an existing user from claims.
     * Default is an empty array — return [] to skip updates on re-login.
     *
     * @return array<string, mixed>
     */
    public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
    {
        return [];
    }

    /**
     * Unique columns that may collide when persisting claim data. The SDK
     * consults this list when a unique-constraint violation fires to figure
     * out which column caused the conflict, so `resolveHuwiyaConflict` can
     * receive the right column name.
     *
     * @return array<int, string>
     */
    public function getHuwiyaConflictColumns(): array
    {
        return ['phone', 'email'];
    }

    /**
     * Decide what to do when writing a claim hits a unique-constraint
     * violation on a recyclable column (typically phone or email). This is
     * the single seam for "phone recycling" policy — the SDK has already
     * detected the conflict, found the colliding row, and will retry the
     * write once after this method returns.
     *
     * Default: throw HuwiyaConflictException. Override to implement your
     * policy (delete the stale row, transfer ownership, throw a custom
     * exception with a support flow, etc).
     *
     * @throws HuwiyaConflictException
     */
    public function resolveHuwiyaConflict(
        TokenClaims $claims,
        self $existingRow,
        string $conflictingColumn,
    ): void {
        throw HuwiyaConflictException::make($claims, $existingRow, $conflictingColumn);
    }

    // =========================================================================
    //  PUBLIC ENTRYPOINTS — called by the guards. Do not override.
    // =========================================================================

    /**
     * Find or create a user from Huwiya token claims. This is the entrypoint
     * invoked by the `huwiya-web` and `huwiya-api` guards after successful
     * JWT verification.
     *
     * @throws HuwiyaUserNotFoundException
     * @throws HuwiyaConflictException
     */
    public static function findOrCreateFromHuwiya(TokenClaims $claims, ?string $guard = null): static
    {
        event(new HuwiyaAuthenticating($claims, $guard));

        $user = static::performHuwiyaResolution($claims, $guard);

        if ($user === null) {
            $instance = new static;

            if (! $instance->shouldAutoRegister($claims)) {
                throw new HuwiyaUserNotFoundException(
                    'User not found and auto-registration is disabled.'
                );
            }

            event(new HuwiyaUserCreating($claims, $guard));

            $user = static::performHuwiyaCreation($claims);

            event(new HuwiyaUserCreated($claims, $user, $guard));
        } else {
            event(new HuwiyaUserUpdating($claims, $user, $guard));

            $user->performHuwiyaUpdate($claims);

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

    // =========================================================================
    //  ORCHESTRATION — internal. DO NOT override on your model.
    //  These methods wire events and handle conflict retries. Overriding
    //  them breaks event dispatch and conflict-resolution invariants.
    // =========================================================================

    /**
     * @internal
     */
    public static function performHuwiyaResolution(TokenClaims $claims, ?string $guard = null): ?static
    {
        $instance = new static;
        $query = $instance->huwiyaQueryForClaims(static::newHuwiyaQuery(), $claims);

        event(new HuwiyaUserResolving($claims, $query, $guard));

        return $query->first();
    }

    /**
     * @internal
     *
     * @throws HuwiyaConflictException
     */
    public static function performHuwiyaCreation(TokenClaims $claims): static
    {
        $instance = new static;

        $attempt = fn () => static::create(array_merge(
            [$instance->getHuwiyaIdentifierColumn() => $claims->id],
            $instance->getHuwiyaCreateAttributes($claims),
        ));

        try {
            return $attempt();
        } catch (UniqueConstraintViolationException $e) {
            static::dispatchHuwiyaConflict($claims, $e);
        }

        // Retry exactly once after the app's policy runs. A second collision
        // means the policy didn't clear the conflict — surface it.
        try {
            return $attempt();
        } catch (UniqueConstraintViolationException $e) {
            throw HuwiyaConflictException::unresolved($claims, $e);
        }
    }

    /**
     * @internal
     *
     * @throws HuwiyaConflictException
     */
    public function performHuwiyaUpdate(TokenClaims $claims): void
    {
        $attributes = $this->getHuwiyaUpdateAttributes($claims);

        if ($attributes === []) {
            return;
        }

        try {
            $this->update($attributes);

            return;
        } catch (UniqueConstraintViolationException $e) {
            static::dispatchHuwiyaConflict($claims, $e);
        }

        try {
            $this->update($attributes);
        } catch (UniqueConstraintViolationException $e) {
            throw HuwiyaConflictException::unresolved($claims, $e);
        }
    }

    /**
     * @internal
     *
     * Detect the conflicting column from the driver exception, locate the
     * existing row, and hand off to the app's resolveHuwiyaConflict policy.
     */
    protected static function dispatchHuwiyaConflict(
        TokenClaims $claims,
        UniqueConstraintViolationException $exception,
    ): void {
        $instance = new static;
        $column = static::detectConflictColumn($exception, $instance->getHuwiyaConflictColumns());

        if ($column === null || ! property_exists($claims, $column)) {
            throw HuwiyaConflictException::unresolved($claims, $exception);
        }

        $value = $claims->{$column};

        if ($value === null || $value === '') {
            throw HuwiyaConflictException::unresolved($claims, $exception);
        }

        $existing = static::newHuwiyaQuery()->where($column, $value)->first();

        if ($existing === null) {
            throw HuwiyaConflictException::unresolved($claims, $exception);
        }

        $instance->resolveHuwiyaConflict($claims, $existing, $column);
    }

    /**
     * @internal
     *
     * @param  array<int, string>  $candidates
     */
    protected static function detectConflictColumn(
        UniqueConstraintViolationException $exception,
        array $candidates,
    ): ?string {
        // Each major driver names the constraint/column in a predictable way,
        // but surrounded by enough other text (SQL, values, etc.) that a naive
        // scan hits false positives. We extract the first identifier that
        // follows one of the well-known markers and match it against the
        // candidate list.
        $message = $exception->getMessage();

        // SQLite: "UNIQUE constraint failed: users.email" — take the token
        // after the last dot on the same line, before whitespace.
        if (preg_match('/constraint\s+failed:\s*(?:\w+\.)*(\w+)/i', $message, $m)) {
            $token = strtolower($m[1]);

            foreach ($candidates as $column) {
                if ($token === strtolower($column)) {
                    return $column;
                }
            }
        }

        // MySQL: "for key 'users.email_unique'" — the constraint name often
        // embeds the column. Scan candidates against the quoted key name.
        if (preg_match("/for\s+key\s+['\"`]([^'\"`]+)['\"`]/i", $message, $m)) {
            $key = strtolower($m[1]);

            foreach ($candidates as $column) {
                if (str_contains($key, strtolower($column))) {
                    return $column;
                }
            }
        }

        // Postgres: `duplicate key value violates unique constraint "users_email_key"`
        if (preg_match('/unique\s+constraint\s+["\']([^"\']+)["\']/i', $message, $m)) {
            $key = strtolower($m[1]);

            foreach ($candidates as $column) {
                if (str_contains($key, strtolower($column))) {
                    return $column;
                }
            }
        }

        return null;
    }
}
