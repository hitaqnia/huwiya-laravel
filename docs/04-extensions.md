# Extensions

The SDK ships the minimum it needs to — guards, JWT verification, a lifecycle trait, events. Anything built on top of that is an **extension**: application code that composes the SDK's policy hooks into higher-level features.

The recipes below are plain application code. Drop them into your User model, adapt, own. Nothing to enable in the SDK, no hidden behavior, no new concepts.

## Invitations

**Scenario.** Admins pre-seed user rows with a `phone` (no `huwiya_id`). On first login, match the Huwiya token to a pre-seeded row by phone and stamp the `huwiya_id` — effectively linking a pending record to its Huwiya identity. Optionally reject anyone who isn't pre-seeded.

**Mechanism.** `huwiya_id` is nullable, lookup is fully overridable, and `getHuwiyaUpdateAttributes` can stamp the id as part of the normal update path.

```php
use Huwiya\InteractsWithHuwiya;
use Huwiya\TokenClaims;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use InteractsWithHuwiya;

    // Look up by huwiya_id first, then fall back to an unclaimed row
    // matching on phone. Real accounts win over stale invitations via
    // the ORDER BY clause.
    public function huwiyaQueryForClaims(Builder $query, TokenClaims $claims): Builder
    {
        return $query->where(function (Builder $q) use ($claims) {
            $q->where('huwiya_id', $claims->id)
              ->orWhere(fn ($q2) => $q2->whereNull('huwiya_id')->where('phone', $claims->phone));
        })->orderByRaw('huwiya_id IS NULL');
    }

    // On update, always stamp the id. No-op for real accounts; claim
    // step for invitations.
    public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
    {
        return [
            'huwiya_id' => $claims->id,
            'name'      => $claims->name,
            'email'     => $claims->email,
        ];
    }

    // Invite-only onboarding — reject anyone who isn't pre-seeded.
    public function shouldAutoRegister(?TokenClaims $claims = null): bool
    {
        return false;
    }
}
```

Seed from an admin tool:

```php
User::create(['phone' => '+9647700000001', 'name' => 'Pending Invitee']);
```

That's the entire feature. One query resolves the user, the claim happens as part of the normal update path, and the SDK itself is not aware of invitations.

## Phone / email recycling

**Scenario.** A phone number (or email) previously attached to user A is later reassigned by the telco to user B. When user B first logs into your app, the SDK tries to write `phone = X` and hits the unique constraint your schema enforces. Before, that would crash with a database driver exception.

**Mechanism.** The SDK catches the unique-constraint violation, identifies which column collided, finds the stale row, and hands off to your `resolveHuwiyaConflict` policy. The SDK retries the write exactly once after your policy returns.

The default policy throws `HuwiyaConflictException` (→ `409 Conflict` on the callback). Override it for any of the policies below.

### Policy: delete the stale row

For apps where the old account is disposable — social apps, utility tools, casual accounts.

```php
public function resolveHuwiyaConflict(
    TokenClaims $claims,
    self $existingRow,
    string $conflictingColumn,
): void {
    $existingRow->delete();
}
```

### Policy: detach the conflicting column

Preserve the old account for audit, but free the column so the new user can take it. Works well when the colliding column is nullable (e.g. `email`). For NOT NULL columns like `phone`, you'd delete or move instead.

```php
public function resolveHuwiyaConflict(
    TokenClaims $claims,
    self $existingRow,
    string $conflictingColumn,
): void {
    $existingRow->update([$conflictingColumn => null]);
}
```

### Policy: reject with a support flow

Banking, healthcare, or any app where automatic resolution is unacceptable.

```php
use App\Exceptions\PhoneReassignmentException;

public function resolveHuwiyaConflict(
    TokenClaims $claims,
    self $existingRow,
    string $conflictingColumn,
): void {
    throw new PhoneReassignmentException(
        "The {$conflictingColumn} provided is already on file. Contact support."
    );
}
```

Hook your custom exception into the application-level exception handler to render a support-request page.

## Tenant-scoped lookup

**Scenario.** Multi-tenant app. The same Huwiya subject can exist under multiple tenants, and lookups must be scoped.

```php
public static function newHuwiyaQuery(): Builder
{
    return static::query()->where('tenant_id', app('currentTenant')->id);
}
```

Provisioning per tenant:

```php
public function getHuwiyaCreateAttributes(TokenClaims $claims): array
{
    return [
        'name'      => $claims->name,
        'phone'     => $claims->phone,
        'tenant_id' => app('currentTenant')->id,
    ];
}
```

## Role-from-scopes

**Scenario.** The Huwiya token carries scopes like `admin` or `staff`; you want to mirror them as application roles on every login.

```php
use Huwiya\Events\HuwiyaAuthenticated;
use Illuminate\Support\Facades\Event;

Event::listen(HuwiyaAuthenticated::class, function (HuwiyaAuthenticated $event) {
    $event->user->syncRoles(array_intersect(
        $event->claims->scopes,
        ['admin', 'staff', 'viewer'],
    ));
});
```

A listener is a better fit than a trait override here — the role-sync is an application cross-cutting concern, not a per-model decision.
