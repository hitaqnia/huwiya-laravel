<?php

namespace Huwiya;

use Huwiya\Events\HuwiyaAuthenticated;
use Huwiya\Events\HuwiyaAuthenticating;
use Huwiya\Events\HuwiyaInvitationClaimed;
use Huwiya\Events\HuwiyaInvitationClaiming;
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
     * Map of Huwiya claim keys -> local DB column names.
     *
     * Default covers every claim Huwiya provides. Override per model to
     * disable fields (omit the key or set the value to false) or rename
     * columns (change the value). `huwiya_id` is always included via
     * getHuwiyaFieldsMap().
     *
     * Supported claim keys: phone, email, name, locale, zoneinfo, theme.
     *
     * @var array<string, string|false>
     */
    protected array $huwiyaFieldsMap = [
        'phone' => 'phone',
        'email' => 'email',
        'name' => 'name',
        'locale' => 'locale',
        'zoneinfo' => 'zoneinfo',
        'theme' => 'theme',
    ];

    /**
     * Ensure Huwiya-synced columns are mass-assignable without clobbering
     * the developer's own $fillable / $guarded configuration.
     */
    public function initializeInteractsWithHuwiya(): void
    {
        if ($this->totallyGuarded()) {
            return;
        }

        $columns = array_values($this->getHuwiyaFieldsMap());
        $missing = array_values(array_diff($columns, $this->getFillable()));

        if ($missing !== []) {
            $this->mergeFillable($missing);
        }
    }

    // -------------------------------------------------------------------------
    //  Identifier, Field Map & Gates
    // -------------------------------------------------------------------------

    /**
     * Get the column name that stores the Huwiya subject identifier.
     */
    public function getHuwiyaIdentifierColumn(): string
    {
        return 'huwiya_id';
    }

    /**
     * Resolved map of claim keys -> column names for this model.
     * `huwiya_id` is always force-included; `false`/null/empty values are stripped.
     *
     * @return array<string, string>
     */
    public function getHuwiyaFieldsMap(): array
    {
        $map = array_filter(
            $this->huwiyaFieldsMap,
            fn ($v) => $v !== false && $v !== null && $v !== '',
        );

        $map['huwiya_id'] = $this->getHuwiyaIdentifierColumn();

        return $map;
    }

    /**
     * Static helper consumed by the `huwiyaFields()` Blueprint macro so that
     * schema and runtime read the same source of truth.
     *
     * @return array<string, string>
     */
    public static function huwiyaFieldsSchema(): array
    {
        return (new static)->getHuwiyaFieldsMap();
    }

    /**
     * Determine if new users should be auto-registered on first login.
     */
    public function shouldAutoRegister(?TokenClaims $claims = null): bool
    {
        return true;
    }

    /**
     * Determine if invitation claiming is enabled for this model.
     *
     * When enabled, user resolution falls back to matching by phone
     * (where huwiya_id IS NULL) after failing to find an exact
     * huwiya_id match. Override per model to turn this on.
     */
    public function invitationsEnabled(): bool
    {
        return false;
    }

    /**
     * Determine if the resolved user is an unclaimed invitation row.
     */
    public function isHuwiyaInvitationClaim(): bool
    {
        return $this->getAttribute($this->getHuwiyaIdentifierColumn()) === null;
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
     * When invitations are enabled and a phone column is configured, the
     * query falls back to `huwiya_id IS NULL AND phone = ?` in a single
     * round-trip. A real huwiya_id match is ordered first so an existing
     * account beats a stale invitation sharing the same phone.
     */
    public function huwiyaQueryForClaims(Builder $query, TokenClaims $claims): Builder
    {
        $idColumn = $this->getHuwiyaIdentifierColumn();
        $map = $this->getHuwiyaFieldsMap();
        $phoneColumn = $map['phone'] ?? null;

        if (! $this->invitationsEnabled() || $phoneColumn === null || $claims->phone === '') {
            return $query->where($idColumn, $claims->id);
        }

        $query->where(function (Builder $q) use ($idColumn, $phoneColumn, $claims) {
            $q->where($idColumn, $claims->id)
                ->orWhere(function (Builder $q2) use ($idColumn, $phoneColumn, $claims) {
                    $q2->whereNull($idColumn)->where($phoneColumn, $claims->phone);
                });
        });

        $grammar = $query->getQuery()->getGrammar();
        $wrapped = $grammar->wrap($idColumn);

        return $query->orderByRaw("$wrapped IS NULL");
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
     * Iterates the field map and copies each configured claim to its mapped
     * column. The identifier is merged in by `createHuwiyaUser()`.
     *
     * @return array<string, mixed>
     */
    public function getHuwiyaCreateAttributes(TokenClaims $claims): array
    {
        $attributes = [];

        foreach ($this->getHuwiyaFieldsMap() as $claimKey => $column) {
            if ($claimKey === 'huwiya_id') {
                continue;
            }

            if (! property_exists($claims, $claimKey)) {
                continue;
            }

            $attributes[$column] = $claims->{$claimKey};
        }

        return $attributes;
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

    /**
     * Hook called before a new user is created from Huwiya claims.
     */
    public function beforeHuwiyaCreate(TokenClaims $claims): void {}

    /**
     * Hook called after a new user has been created from Huwiya claims.
     */
    public function afterHuwiyaCreate(TokenClaims $claims): void {}

    // -------------------------------------------------------------------------
    //  Update
    // -------------------------------------------------------------------------

    /**
     * Get the attributes to update on an existing user from Huwiya claims.
     *
     * When $claimingInvitation is true, the identifier column is included
     * so the invitation row gets stamped with the Huwiya subject id.
     *
     * @return array<string, mixed>
     */
    public function getHuwiyaUpdateAttributes(TokenClaims $claims, bool $claimingInvitation = false): array
    {
        $attributes = [];

        foreach ($this->getHuwiyaFieldsMap() as $claimKey => $column) {
            if ($claimKey === 'huwiya_id') {
                if ($claimingInvitation) {
                    $attributes[$column] = $claims->id;
                }
                continue;
            }

            if (! property_exists($claims, $claimKey)) {
                continue;
            }

            $attributes[$column] = $claims->{$claimKey};
        }

        return $attributes;
    }

    /**
     * Update the existing user with data from the given Huwiya claims.
     *
     * Detects invitation claims (huwiya_id currently NULL) and includes
     * the identifier in the update payload.
     */
    public function updateHuwiyaUser(TokenClaims $claims): void
    {
        $attributes = $this->getHuwiyaUpdateAttributes($claims, $this->isHuwiyaInvitationClaim());

        if ($attributes !== []) {
            $this->update($attributes);
        }
    }

    /**
     * Hook called before an existing user is updated from Huwiya claims.
     */
    public function beforeHuwiyaUpdate(TokenClaims $claims): void {}

    /**
     * Hook called after an existing user has been updated from Huwiya claims.
     */
    public function afterHuwiyaUpdate(TokenClaims $claims): void {}

    // -------------------------------------------------------------------------
    //  Orchestration
    // -------------------------------------------------------------------------

    /**
     * Find or create a user from Huwiya token claims.
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

            $instance->beforeHuwiyaCreate($claims);
            event(new HuwiyaUserCreating($claims, $guard));

            $user = static::createHuwiyaUser($claims);

            $user->afterHuwiyaCreate($claims);
            event(new HuwiyaUserCreated($claims, $user, $guard));
        } else {
            $isClaim = $user->isHuwiyaInvitationClaim();

            if ($isClaim) {
                event(new HuwiyaInvitationClaiming($claims, $user, $guard));
            }

            $user->beforeHuwiyaUpdate($claims);
            event(new HuwiyaUserUpdating($claims, $user, $guard));

            $user->updateHuwiyaUser($claims);

            $user->afterHuwiyaUpdate($claims);
            event(new HuwiyaUserUpdated($claims, $user, $guard));

            if ($isClaim) {
                event(new HuwiyaInvitationClaimed($claims, $user, $guard));
            }
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
