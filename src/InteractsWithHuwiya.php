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
     * Decide what to do when a claim would collide with an existing row on a
     * recyclable column (typically phone or email). Called once per (row,
     * column) collision — so if the claim's phone matches one row and the
     * claim's email matches another, this runs twice; if a single existing
     * row collides on both columns, it runs twice (once per column) against
     * the same row.
     *
     * Invocation happens *before* the write, from a single preflight SELECT.
     * Your policy should clear the conflict (delete the stale row, null the
     * column, transfer ownership, etc). After every (row, column) has been
     * dispatched, the SDK performs the create/update; if a concurrent insert
     * caused a race, the post-exception fallback dispatches once more and
     * retries the write.
     *
     * Between dispatches, the SDK re-reads the colliding row from the DB. If
     * a previous dispatch deleted it, subsequent dispatches for that row are
     * skipped; if a previous dispatch cleared the next column (as a side
     * effect), that column's dispatch is skipped too. This means policies
     * that delete the whole row safely collapse multi-column collisions into
     * a single call.
     *
     * Default: throw HuwiyaConflictException. Override to implement your
     * recycling policy.
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

        $createAttributes = $instance->getHuwiyaCreateAttributes($claims);

        // Preflight: find every recyclable-column collision in one query and
        // hand each to the policy. Much cheaper than create()→catch→retry and
        // — unlike the catch path — surfaces *all* colliding columns, not just
        // whichever one the DB happened to raise first.
        static::preflightHuwiyaConflicts($claims, $createAttributes, excludingId: null);

        $attempt = fn () => static::create(array_merge(
            [$instance->getHuwiyaIdentifierColumn() => $claims->id],
            $createAttributes,
        ));

        // A race between preflight and INSERT can still hit the unique index —
        // fall back to the exception path so concurrent inserts get dispatched.
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

        // Preflight: resolve every recyclable-column collision in one query
        // before touching the DB. Exclude this row's own key so an unchanged
        // phone/email doesn't look like a self-collision.
        static::preflightHuwiyaConflicts($claims, $attributes, excludingId: $this->getKey());

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
     * Find every row that would collide with the claim data on any recyclable
     * column, and invoke `resolveHuwiyaConflict` once per (row, column) pair.
     *
     * This happens before the write, so the app's policy can clear all
     * conflicts in a single auth flow — even when the same claim collides on
     * multiple columns (e.g. phone *and* email). One indexed SELECT replaces
     * the create→fail→retry loop in the common case.
     *
     * Dispatch rules:
     *  - Columns in `getHuwiyaConflictColumns()` that aren't being written
     *    (not in `$attributes`) are skipped — no write, no possible conflict.
     *  - Columns whose claim property is null/empty are skipped (mirrors the
     *    post-exception dispatcher; null values can't violate a unique index).
     *  - If the same existing row collides on multiple columns, the policy is
     *    called once per (row, column). Deduplicating by row would hide the
     *    second column from a policy that needs to clear each independently.
     *    Between calls the row is refreshed from the DB — if the previous
     *    dispatch deleted it, later dispatches for that row are skipped, and
     *    if the previous dispatch cleared the next column as a side effect,
     *    that dispatch is skipped too.
     *  - Dispatch order is the order in `getHuwiyaConflictColumns()`, so apps
     *    can rely on (say) phone being resolved before email.
     *  - `$excludingId` is the primary key of the row being updated (or null
     *    on create). Rows with that key are ignored so an unchanged value on
     *    the same row isn't mistaken for a collision with someone else.
     *
     * @param  array<string, mixed>  $attributes  Columns about to be written.
     * @param  int|string|null  $excludingId  Primary key of the row being updated, if any.
     *
     * @throws HuwiyaConflictException
     */
    protected static function preflightHuwiyaConflicts(
        TokenClaims $claims,
        array $attributes,
        int|string|null $excludingId,
    ): void {
        $instance = new static;

        // Build the {column => claim-value} map for columns that are (a) being
        // written, (b) configured as recyclable, (c) readable from the claim,
        // and (d) non-empty. Anything filtered out here cannot trip a unique
        // constraint on this write, so skipping it is safe and saves a query.
        $candidates = [];

        foreach ($instance->getHuwiyaConflictColumns() as $column) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            if (! property_exists($claims, $column)) {
                continue;
            }

            $value = $claims->{$column};

            if ($value === null || $value === '') {
                continue;
            }

            $candidates[$column] = $value;
        }

        if ($candidates === []) {
            return;
        }

        $query = static::newHuwiyaQuery();

        $query->where(function ($q) use ($candidates) {
            foreach ($candidates as $column => $value) {
                $q->orWhere($column, $value);
            }
        });

        if ($excludingId !== null) {
            $query->where($instance->getKeyName(), '!=', $excludingId);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return;
        }

        // Emit (row, column) pairs in the configured column order. A single
        // row that collides on two columns yields two dispatches — one per
        // column — so the app policy can clear each side independently.
        //
        // Between dispatches we refresh the row from the DB because the
        // previous dispatch may have deleted it or mutated its columns
        // (e.g. nulled the email). We skip subsequent dispatches against a
        // gone row, and re-check the column value against the latest state
        // so a policy that nulled column A doesn't also get called for a
        // column B it already cleared as a side effect.
        $rowKey = $instance->getKeyName();
        $alive = [];

        foreach ($rows as $row) {
            $alive[(string) $row->{$rowKey}] = $row;
        }

        foreach ($candidates as $column => $value) {
            foreach ($alive as $key => $row) {
                if ((string) $row->{$column} !== (string) $value) {
                    continue;
                }

                $instance->resolveHuwiyaConflict($claims, $row, $column);

                $refreshed = $row->fresh();

                if ($refreshed === null) {
                    unset($alive[$key]);

                    continue;
                }

                $alive[$key] = $refreshed;
            }
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
