# Customization

The `InteractsWithHuwiya` trait is structured around two distinct sets of methods:

- **Policy hooks** — single-purpose, safe to override. Each one is a small decision about your application's behavior.
- **Orchestration** — internal. Prefixed with `perform`, marked `@internal`. **Do not override these.** They wire events and handle conflict retries; overriding them breaks invariants.

## Policy hooks

Every method on this list is intended to be overridden on your User model.

| Method                          | When to override                                  | Default                                    |
| ------------------------------- | ------------------------------------------------- | ------------------------------------------ |
| `shouldAutoRegister`            | Reject unknown users.                             | Accept — return `true`.                    |
| `huwiyaQueryForClaims`          | Custom lookup (phone fallback, tenant scope).     | Match by `huwiya_id`.                      |
| `newHuwiyaQuery`                | Base query scope (tenant, soft-deletes, eager).   | `static::query()`.                         |
| `getHuwiyaCreateAttributes`     | Persist claim fields on first login.              | `[]` — opt in to what you sync.            |
| `getHuwiyaUpdateAttributes`     | Persist claim fields on re-login.                 | `[]` — opt in to what you sync.            |
| `resolveHuwiyaConflict`         | Phone/email recycling policy.                     | Throw `HuwiyaConflictException`.           |
| `getHuwiyaConflictColumns`      | Add other unique columns to the conflict scan.    | `['phone', 'email']`.                      |
| `getHuwiyaIdentifierColumn`     | Rename the subject-ID column.                     | `'huwiya_id'`.                             |

> **Never override any method prefixed with `perform`.** Those are orchestration — they dispatch events, handle conflict retries, and hold invariants the rest of the SDK relies on.

## Lifecycle order

```
findOrCreateFromHuwiya()
  └─ performHuwiyaResolution()               [orchestration]
       └─ newHuwiyaQuery() → huwiyaQueryForClaims()
            │
            ├─ User found  → performHuwiyaUpdate()     [orchestration]
            │                  → getHuwiyaUpdateAttributes()
            │                  → preflight conflict scan → resolveHuwiyaConflict()
            │                  → write (+ post-exception retry for races)
            │
            └─ Not found   → shouldAutoRegister()
                              ├─ false → HuwiyaUserNotFoundException
                              └─ true  → performHuwiyaCreation()  [orchestration]
                                           → getHuwiyaCreateAttributes()
                                           → preflight conflict scan → resolveHuwiyaConflict()
                                           → write (+ post-exception retry for races)
```

For decoupled side effects — welcome emails, audit logs, analytics — subscribe to [events](05-events.md) rather than overriding the model.

---

## Policy hook reference

### `shouldAutoRegister(?TokenClaims $claims = null): bool`

Return `false` to reject users that don't already exist locally. The callback controller turns the resulting `HuwiyaUserNotFoundException` into a `403 Forbidden`.

```php
public function shouldAutoRegister(?TokenClaims $claims = null): bool
{
    return false;
}
```

The `$claims` argument lets you gate registration dynamically — for example, accepting only tokens carrying a specific scope:

```php
public function shouldAutoRegister(?TokenClaims $claims = null): bool
{
    return in_array('staff', $claims?->scopes ?? [], true);
}
```

### `huwiyaQueryForClaims(Builder $query, TokenClaims $claims): Builder`

Customize how a user is resolved from claims. The default matches `huwiya_id`; override to add fallback columns or multi-tenant filters.

See the [extensions](04-extensions.md) doc for the full invitation-by-phone recipe.

### `newHuwiyaQuery(): Builder`

The base query before `huwiyaQueryForClaims` adds its constraints. Override for tenant scopes, soft-deleted rows, or eager loads.

```php
public static function newHuwiyaQuery(): Builder
{
    return static::query()->withTrashed();
}
```

### `getHuwiyaCreateAttributes(TokenClaims $claims): array`

The attributes persisted when a new user is created. Default is `[]` — the SDK cannot know which columns your schema has, so nothing is synced until you opt in.

```php
public function getHuwiyaCreateAttributes(TokenClaims $claims): array
{
    return [
        'name'  => $claims->name,
        'phone' => $claims->phone,
        'email' => $claims->email,
    ];
}
```

Rename, transform, or derive values freely — it's plain PHP.

### `getHuwiyaUpdateAttributes(TokenClaims $claims): array`

The attributes re-synced on every login. Default is `[]`. Return `[]` explicitly to skip updates.

```php
public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
{
    return [
        'name'  => $claims->name,
        'phone' => $claims->phone,
        'email' => $claims->email,
    ];
}
```

### `resolveHuwiyaConflict(TokenClaims $claims, self $existingRow, string $column): void`

When a create or update would introduce data that collides with an existing row on a recyclable column (phone, email), the SDK calls this method before the write so your policy can clear the conflict.

**How the SDK finds conflicts.** Before each create/update, a single `SELECT` scans every column returned by `getHuwiyaConflictColumns()` against the values on the claim, skipping the row being updated (if any). This happens in one indexed query — much cheaper than writing, catching the unique-constraint violation, and retrying.

**Call shape.** The method is invoked **once per (row, column) collision**, in the order defined by `getHuwiyaConflictColumns()`:

- One row colliding on both `phone` and `email` → the method is called twice, once per column, against that same row.
- Two different rows, one colliding on `phone` and the other on `email` → the method is called twice, once per row.
- The claim's value for a given recyclable column is `null`/empty → that column is skipped (a null value cannot violate a unique index).
- The column is not part of the current `getHuwiya*Attributes` payload → that column is skipped (no write, no possible conflict).

**Between dispatches.** The SDK re-reads the colliding row from the DB between calls:

- If a previous dispatch **deleted** the row, subsequent dispatches for that row are skipped. A `delete()` policy collapses multi-column collisions into a single call.
- If a previous dispatch **cleared the value** the next column would have collided on (as a side effect), that later dispatch is skipped too.
- If the row is still present and still collides, the next (row, column) pair fires.

**After dispatch, the write happens.** If a concurrent insert happened to sneak in between the preflight and the write (a race), the SDK falls back to the old post-exception path: dispatch once more, retry the write once, and surface `HuwiyaConflictException::unresolved` if it still collides.

Default behavior: throw `HuwiyaConflictException` (converted to `409 Conflict` by the callback controller).

```php
// "Delete the stale row" — the simplest, most common policy.
public function resolveHuwiyaConflict(
    TokenClaims $claims,
    self $existingRow,
    string $column,
): void {
    $existingRow->delete();
}
```

```php
// "Null only the colliding column" — preserves the shell of the old account
// for audit, works only if the column is nullable in your schema.
public function resolveHuwiyaConflict(
    TokenClaims $claims,
    self $existingRow,
    string $column,
): void {
    $existingRow->update([$column => null]);
}
```

See [extensions](04-extensions.md) for the three common policies — delete, detach, and reject with a custom support flow.

### `getHuwiyaConflictColumns(): array`

Which unique columns the SDK should inspect when scanning a violation message. Default covers `phone` and `email`. Extend if your schema has other unique columns that you sync from claims.

### `getHuwiyaIdentifierColumn(): string`

The column storing the Huwiya subject ULID. Default is `'huwiya_id'`. Pair the override with a matching `huwiyaIdentifier('sso_id')` call in your migration.

## Which method should I override?

- **"I want to reject unknown users"** → `shouldAutoRegister`
- **"I want to find users by phone if id match fails"** → `huwiyaQueryForClaims`
- **"I want `phone` synced to my users table"** → `getHuwiyaCreateAttributes` + `getHuwiyaUpdateAttributes`
- **"A phone got reassigned by the telco; handle the collision"** → `resolveHuwiyaConflict`
- **"I have tenants and need to scope user lookup"** → `newHuwiyaQuery`
- **"I want to send a welcome email on first login"** → listen to `HuwiyaUserCreated` (see [events](05-events.md))
- **"I want to assign roles on create"** → override `getHuwiyaCreateAttributes` if it's just columns, or listen to `HuwiyaUserCreated` for anything more involved.
