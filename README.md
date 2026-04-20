# Huwiya SDK for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hitaqnia/huwiya-laravel.svg?style=flat-square)](https://packagist.org/packages/hitaqnia/huwiya-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/hitaqnia/huwiya-laravel.svg?style=flat-square)](https://packagist.org/packages/hitaqnia/huwiya-laravel)
[![License](https://img.shields.io/packagist/l/hitaqnia/huwiya-laravel.svg?style=flat-square)](LICENSE)

The official Laravel SDK for the [Huwiya](https://huwiya.id) Identity Provider. It integrates Huwiya-issued JWTs with Laravel's native guard system through two first-class drivers:

- **`huwiya-web`** — OAuth 2.0 Authorization Code flow for session-based web applications.
- **`huwiya-api`** — JWT Bearer authentication for stateless APIs.

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Preparing the User model](#preparing-the-user-model)
- [Registering guards](#registering-guards)
- [Initiating a login](#initiating-a-login)
- [How it works](#how-it-works)
- [Customization](#customization)
  - [Attribute mapping](#attribute-mapping)
  - [User lookup](#user-lookup)
  - [User creation](#user-creation)
  - [User update](#user-update)
  - [Disabling auto-registration](#disabling-auto-registration)
  - [Multiple guards](#multiple-guards)
  - [Authorization denial](#authorization-denial)
  - [Stateful middleware for SPAs](#stateful-middleware-for-spas)
- [Extensions](#extensions)
  - [Invitations](#invitations)
- [Events](#events)
- [Testing](#testing)
- [Errors](#errors)
- [Logging](#logging)
- [Security](#security)
- [Configuration reference](#configuration-reference)
- [Contributing](#contributing)
- [License](#license)

## Requirements

| Dependency | Version |
| ---------- | ------- |
| PHP        | `^8.3`  |
| Laravel    | `^13.0` |

## Installation

Install via Composer:

```bash
composer require hitaqnia/huwiya-laravel
```

The service provider is registered automatically through Laravel's package discovery. Publish the configuration file if you need to customize settings beyond the environment variables:

```bash
php artisan vendor:publish --tag=huwiya-config
```

## Configuration

Add your Huwiya credentials to `.env`:

```dotenv
HUWIYA_PROJECT_ID=your-project-id
HUWIYA_CLIENT_ID=your-client-id
HUWIYA_CLIENT_SECRET=your-client-secret
```

Every other setting has a sensible default — the IdP base URL, redirect URI, JWT algorithm, clock-skew tolerance, rate limit, and so on. See the full [Configuration reference](#configuration-reference) for details.

> The default redirect URI is derived from `APP_URL`. Make sure `APP_URL` matches the host registered with the IdP, or set `HUWIYA_REDIRECT_URI` explicitly.

## Preparing the User model

### 1. Add the trait

Add `InteractsWithHuwiya` to your `User` model:

```php
use Huwiya\InteractsWithHuwiya;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use InteractsWithHuwiya;
}
```

The trait is mandatory for every model bound to a Huwiya guard. Both guards throw `AuthConfigurationException` if the provider model does not use it.

### 2. Write your migration

The SDK does not own your `users` schema — it ships a single Blueprint macro, `huwiyaIdentifier()`, which creates the Huwiya subject column with the correct type and constraints. Compose it into your own migration alongside any other columns your application needs:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->huwiyaIdentifier();          // nullable unique ULID
            $table->string('phone')->unique();
            $table->string('email')->unique()->nullable();
            $table->string('name')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
```

`huwiyaIdentifier()` defaults to a column named `huwiya_id`. Pass a string to rename it — `$table->huwiyaIdentifier('sso_id')` — as long as the name matches `getHuwiyaIdentifierColumn()` on your model.

The column is nullable to support the [invitation recipe](#recipe-invitations) where rows can exist locally before they are linked to a Huwiya subject. If you do not use invitations, leave it nullable anyway — the unique constraint prevents duplicates, and NULLs cost nothing.

## Registering guards

Switch the `web` and/or `api` guards in `config/auth.php` to the drivers provided by the package:

```php
'guards' => [
    'web' => [
        'driver' => 'huwiya-web',
        'provider' => 'users',
    ],
    'api' => [
        'driver' => 'huwiya-api',
        'provider' => 'users',
    ],
],
```

## Initiating a login

The SDK does not ship a login route. Start the OAuth flow from a route you own by calling `Huwiya::redirect($guard)`, passing the guard the user should end up logged into:

```php
use Huwiya\Facades\Huwiya;

Route::get('/login', fn () => Huwiya::redirect('web'))->name('login');
Route::get('/admin/login', fn () => Huwiya::redirect('admin'))->name('admin.login');
```

Optionally pass an intended post-login URL as the second argument:

```php
Route::get('/login', fn () => Huwiya::redirect('web', '/dashboard'));
```

`Huwiya::redirect()` validates the guard (it must use `huwiya-web` and reference a provider whose model uses `InteractsWithHuwiya`), stores the guard and intended URL atomically alongside the OAuth `state` in the session, and redirects to the IdP. Passing an empty, unknown, or incompatible guard throws `InvalidGuardException`.

The callback route `/huwiya/callback` is registered automatically and rate-limited at 30 requests per minute per IP. Register the callback URL with the IdP as an allowed redirect URI.

## How it works

### Web flow

1. The user hits a route that calls `Huwiya::redirect($guard)`. The SDK validates the guard, generates a cryptographically random `state`, stores `['state' => ..., 'guard' => ..., 'intended' => ...]` under the session key `huwiya.oauth`, and redirects to the IdP.
2. The IdP redirects back to `/huwiya/callback`. The SDK pulls (and clears) the bound session payload, verifies the `state` with a timing-safe comparison, re-validates the guard against the current auth configuration, exchanges the authorization code for a JWT at `{url}/oauth/token`, verifies the JWT signature and claims, and invokes `User::findOrCreateFromHuwiya($claims, $guard)`.
3. On success, the user is logged into the bound guard. The session is regenerated. The response redirects to the intended URL, or `config('huwiya.home')` as a fallback.

### API flow

For each request, the `huwiya-api` guard:

1. Reads the `Authorization: Bearer <jwt>` header.
2. Verifies the JWT signature against the IdP's JWKS (cached for one hour, invalidated under a lock on unknown `kid` to support seamless key rotation).
3. Validates `alg`, `exp` (with configurable leeway), `iss`, and `aud`.
4. Invokes `User::findOrCreateFromHuwiya($claims, $guard)` and attaches the decoded `TokenClaims` to `$user->huwiyaToken`.

The flow is stateless — no session is created or read.

## Customization

The `InteractsWithHuwiya` trait ships sensible defaults. Every step of the authentication lifecycle is exposed as a single overridable method on your model — no subclassing of controllers or guards is required. Customization is per-model, so `User` and `Admin` can each have their own rules.

Lifecycle order:

```
findOrCreateFromHuwiya()
  └─ resolveHuwiyaUser()
       └─ newHuwiyaQuery() → huwiyaQueryForClaims()
            │
            ├─ User found     → updateHuwiyaUser()
            │                     (uses getHuwiyaUpdateAttributes())
            │
            └─ Not found      → shouldAutoRegister()
                                 ├─ false → HuwiyaUserNotFoundException
                                 └─ true  → createHuwiyaUser()
                                              (uses getHuwiyaCreateAttributes())
```

For decoupled side effects — welcome emails, audit logs, analytics — subscribe to [events](#events) rather than overriding the model.

### Attribute mapping

Override `getHuwiyaCreateAttributes()` and `getHuwiyaUpdateAttributes()` to control which claim fields are persisted to your users table, and under which column names. The default is minimal — just `name` — because the SDK cannot know which columns your schema has.

```php
use Huwiya\TokenClaims;

public function getHuwiyaCreateAttributes(TokenClaims $claims): array
{
    return [
        'phone' => $claims->phone,
        'email' => $claims->email,
        'name'  => $claims->name,
    ];
}

public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
{
    return [
        'name' => $claims->name,
        // Skip phone/email on re-login if you don't want them to churn.
    ];
}
```

Rename columns, derive values, or apply per-claim transformations freely — this is plain PHP. To skip updates entirely on re-login, return an empty array from `getHuwiyaUpdateAttributes()`.

Claims available on `$claims`:

| Property   | Type       | Required | Notes                                            |
| ---------- | ---------- | -------- | ------------------------------------------------ |
| `id`       | `string`   | yes      | ULID — use with `getHuwiyaIdentifierColumn()`.   |
| `name`     | `string`   | yes      |                                                  |
| `phone`    | `string`   | yes      | Huwiya is phone-first; always present.           |
| `email`    | `?string`  | no       | `null` when the user has no email on record.     |
| `locale`   | `string`   | no       | Empty string if the IdP omitted it.              |
| `zoneinfo` | `string`   | no       | Empty string if the IdP omitted it.              |
| `theme`    | `string`   | no       | Empty string if the IdP omitted it.              |
| `scopes`   | `string[]` | yes      |                                                  |

### User lookup

By default, users are matched by `huwiya_id`. Override `huwiyaQueryForClaims()` to customize the lookup — for example, to fall back to phone-based lookup for invitation claiming, or to match by email when the subject ID is not yet known:

```php
use Huwiya\TokenClaims;
use Illuminate\Database\Eloquent\Builder;

public function huwiyaQueryForClaims(Builder $query, TokenClaims $claims): Builder
{
    return $query->where(function (Builder $q) use ($claims) {
        $q->where('huwiya_id', $claims->id)
          ->orWhere(fn ($q2) => $q2->whereNull('huwiya_id')->where('email', $claims->email));
    });
}
```

Override `newHuwiyaQuery()` to customize the base query — apply tenant scopes, include soft-deleted rows, or eager-load relationships:

```php
public static function newHuwiyaQuery(): Builder
{
    return static::query()->withTrashed();
}
```

### User creation

Override `createHuwiyaUser()` when you need creation logic beyond attribute mapping — assigning roles, attaching to a tenant, wrapping in a transaction:

```php
use Huwiya\TokenClaims;
use Illuminate\Support\Facades\DB;

public static function createHuwiyaUser(TokenClaims $claims): static
{
    return DB::transaction(function () use ($claims) {
        $instance = new static;

        $user = static::create(array_merge(
            [$instance->getHuwiyaIdentifierColumn() => $claims->id],
            $instance->getHuwiyaCreateAttributes($claims),
        ));

        $user->assignRole(
            in_array('admin', $claims->scopes, true) ? 'admin' : 'member'
        );

        return $user;
    });
}
```

### User update

Override `updateHuwiyaUser()` when you need update logic beyond attribute sync — syncing roles from scopes, recording last-login timestamp, conditionally skipping updates:

```php
use Huwiya\TokenClaims;

public function updateHuwiyaUser(TokenClaims $claims): void
{
    $this->update(array_merge(
        $this->getHuwiyaUpdateAttributes($claims),
        ['last_login_at' => now()],
    ));

    $this->syncRolesFromScopes($claims->scopes);
}
```

The default implementation delegates to `getHuwiyaUpdateAttributes()` and skips the write when it returns an empty array.

### Disabling auto-registration

By default, users that do not exist locally are created on first login. Override `shouldAutoRegister()` to reject unknown users:

```php
public function shouldAutoRegister(?Huwiya\TokenClaims $claims = null): bool
{
    return false;
}
```

The `$claims` argument gives you access to the incoming token, so you can gate registration dynamically — for example, allowing only tokens carrying a specific scope:

```php
public function shouldAutoRegister(?Huwiya\TokenClaims $claims = null): bool
{
    return in_array('staff', $claims?->scopes ?? [], true);
}
```

When disabled, unknown users cause `HuwiyaUserNotFoundException`. The web callback catches it and returns HTTP 403; the API guard simply returns no user, which `auth:api` middleware turns into 401.

### Multiple guards

You may register any number of guards using the `huwiya-web` and `huwiya-api` drivers:

```php
'guards' => [
    'web'   => ['driver' => 'huwiya-web', 'provider' => 'users'],
    'admin' => ['driver' => 'huwiya-web', 'provider' => 'admins'],
    'api'   => ['driver' => 'huwiya-api', 'provider' => 'users'],
],
```

The callback route is shared by every `huwiya-web` guard. The guard the user is logged into is chosen when you call `Huwiya::redirect($guard)` and travels with the OAuth `state` in one atomic session entry. Expose a separate login route per guard:

```php
Route::get('/login',       fn () => Huwiya::redirect('web'));
Route::get('/admin/login', fn () => Huwiya::redirect('admin'));
```

Because the guard name is a server-side literal, users cannot influence it via query string or cookie. The callback re-validates the bound guard against the current auth configuration before calling `login()` — if the guard is removed or its driver is changed between the redirect and the callback, the request fails rather than silently logging into an unintended guard.

### Authorization denial

When the user denies consent at the IdP, the callback dispatches a configurable handler. The default redirects to `/`. Override it in a service provider's `boot()`:

```php
use Huwiya\Facades\Huwiya;

public function boot(): void
{
    Huwiya::whenAuthorizationDenied(function (?string $error, ?string $description) {
        return redirect()
            ->route('login')
            ->with('error', $description ?? 'Authorization was denied.');
    });
}
```

The callback may declare zero, one, or two parameters; the SDK inspects its arity and dispatches accordingly. The handler is stored in a container-scoped singleton, so it resets per request under Laravel Octane and other resident runtimes.

### Stateful middleware for SPAs

The stateful middleware required for cookie-based SPA authentication is **not** registered automatically. Opt in by appending it to your global middleware stack in `bootstrap/app.php`:

```php
use Huwiya\Http\Middleware\EnsureFrontendRequestsAreStateful;

->withMiddleware(function (Middleware $middleware) {
    $middleware->append(EnsureFrontendRequestsAreStateful::class);
})
```

Configure `HUWIYA_STATEFUL_DOMAINS` with a comma-separated list of trusted frontend origins. Localhost variants, the host from `APP_URL`, and `FRONTEND_URL` are included by default.

Session cookie hardening is your responsibility. Set these in `config/session.php`:

```php
'http_only' => true,
'same_site' => 'lax',
```

You may swap the cookie and CSRF middleware used by the stateful pipeline via `config/huwiya.php`:

```php
'middleware' => [
    'encrypt_cookies'     => \App\Http\Middleware\EncryptCookies::class,
    'validate_csrf_token' => \App\Http\Middleware\ValidateCsrfToken::class,
],
```

## Extensions

The SDK ships the minimum it needs to ship — guards, JWT verification, lifecycle trait, events. Anything built on top of that is an **extension**: application code that uses the SDK's extension points (`huwiyaQueryForClaims()`, `getHuwiyaCreateAttributes()`, `getHuwiyaUpdateAttributes()`, `shouldAutoRegister()`, and the event bus) to implement higher-level features.

The sections below document patterns we've seen repeatedly. Each is plain application code — drop it into your User model or a service provider, adapt it, own it. No flags to toggle in the SDK, no hidden behavior, no new concepts.

### Invitations

Pre-seed user rows with `phone` but no `huwiya_id`. On first login, match the token to a pre-seeded row by phone and stamp the `huwiya_id` — effectively linking a pending record to its Huwiya identity.

Because `huwiya_id` is nullable and lookup is fully overridable, this takes roughly ten lines of application code.

```php
use Huwiya\InteractsWithHuwiya;
use Huwiya\TokenClaims;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use InteractsWithHuwiya;

    // 1. Look up by huwiya_id first; fall back to an unclaimed row with
    //    a matching phone. A real account always wins over a stale invite.
    public function huwiyaQueryForClaims(Builder $query, TokenClaims $claims): Builder
    {
        return $query->where(function (Builder $q) use ($claims) {
            $q->where('huwiya_id', $claims->id)
              ->orWhere(fn ($q2) => $q2->whereNull('huwiya_id')->where('phone', $claims->phone));
        })->orderByRaw('huwiya_id IS NULL');
    }

    // 2. On update, always stamp the id. This is a no-op for real accounts
    //    (already set to the same value) and the claim step for invitations.
    public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
    {
        return [
            'huwiya_id' => $claims->id,
            'name'      => $claims->name,
            'email'     => $claims->email,
        ];
    }

    // 3. Invite-only onboarding: reject anyone who isn't pre-seeded.
    public function shouldAutoRegister(?TokenClaims $claims = null): bool
    {
        return false;
    }
}
```

Pre-seed invitations from an admin tool:

```php
User::create(['phone' => '+9647700000001', 'name' => 'Pending Invitee']);
```

That's the entire feature. A single query resolves the user on login, the claim happens as part of the normal update path, and the SDK does not need to know anything about invitations.

> **Concurrency.** If two browsers race to claim the same row, Laravel's database driver will raise `UniqueConstraintViolationException` on the loser. Wrap `Huwiya::redirect()` in an exception handler on your login route, or catch the exception in a listener on `HuwiyaUserResolving` and re-query. In practice this is vanishingly rare — it requires two devices logging into the same unclaimed account within a few milliseconds.

## Events

The SDK dispatches lifecycle events from inside the `InteractsWithHuwiya` trait, so both the web (OAuth callback) and API (JWT bearer) flows fire them consistently. Every event carries the `TokenClaims`, the `Authenticatable` user (when available), and the guard name.

| Event                     | Payload                          | Fired when                                                                               |
| ------------------------- | -------------------------------- | ---------------------------------------------------------------------------------------- |
| `HuwiyaAuthenticating`    | `$claims`, `$guard`              | Before any database work.                                                                |
| `HuwiyaUserResolving`     | `$claims`, `$query`, `$guard`    | Before the lookup query executes. Listeners may further constrain `$query`.              |
| `HuwiyaUserCreating`      | `$claims`, `$guard`              | Before a new user is created.                                                            |
| `HuwiyaUserCreated`       | `$claims`, `$user`, `$guard`     | After a new user has been created.                                                       |
| `HuwiyaUserUpdating`      | `$claims`, `$user`, `$guard`     | Before an existing user is updated.                                                      |
| `HuwiyaUserUpdated`       | `$claims`, `$user`, `$guard`     | After an existing user has been updated.                                                 |
| `HuwiyaAuthenticated`     | `$claims`, `$user`, `$guard`     | After the user has been resolved (created or updated). Fires on every authentication.    |

All event classes live under the `Huwiya\Events` namespace.

**Example — welcome email on first login:**

```php
use Huwiya\Events\HuwiyaUserCreated;

Event::listen(HuwiyaUserCreated::class, function (HuwiyaUserCreated $event) {
    Mail::to($event->user)->queue(new WelcomeMail($event->user));
});
```

**Example — track last login on every sign-in:**

```php
use Huwiya\Events\HuwiyaAuthenticated;

Event::listen(HuwiyaAuthenticated::class, function (HuwiyaAuthenticated $event) {
    $event->user->update(['last_login_at' => now()]);
});
```

**Example — scope lookups to a tenant via a listener:**

```php
use Huwiya\Events\HuwiyaUserResolving;

Event::listen(HuwiyaUserResolving::class, function (HuwiyaUserResolving $event) {
    $event->query->where('tenant_id', session('tenant_id'));
});
```

## Testing

Authenticate a user in tests without the OAuth flow:

```php
use Huwiya\Facades\Huwiya;

Huwiya::actingAs($user, 'web');
Huwiya::actingAs($user, 'api');
```

Run the package's own test suite:

```bash
composer test
```

## Errors

The SDK uses two distinct error mechanisms, each matched to the kind of failure it represents.

### Token rejection — the Result pattern

A third-party-signed JWT arriving over HTTP may be rejected for many routine reasons — bad signature, wrong issuer, expired, malformed. These are expected domain outcomes, not programmer errors. `Huwiya::decodeAndVerifyToken()` returns `Huwiya\Support\Result<TokenClaims>`:

```php
use Huwiya\Huwiya;
use Huwiya\Support\TokenRejection;

$result = Huwiya::decodeAndVerifyToken($jwt);

if ($result->isSuccess()) {
    $claims = $result->getData();
    // ...
} else {
    $code = $result->getError()->getCode();

    match (true) {
        $code === TokenRejection::EXPIRED         => /* 401, prompt refresh */,
        in_array($code, [
            TokenRejection::BAD_SIGNATURE,
            TokenRejection::BAD_ISSUER,
            TokenRejection::BAD_AUDIENCE,
        ], true)                                  => /* 401, reject */,
        in_array($code, [
            TokenRejection::MALFORMED,
            TokenRejection::MISSING_CLAIMS,
        ], true)                                  => /* 400, bad input */,
        in_array($code, [
            TokenRejection::JWKS_UNAVAILABLE,
            TokenRejection::UNKNOWN_KID,
            TokenRejection::UNSUPPORTED_KEY_TYPE,
        ], true)                                  => /* 502, IdP issue */,
    };
}
```

Callers that do not care about the specific reason may use `Huwiya::tryDecodeAndVerifyToken()`, which returns `TokenClaims` on success and `null` on any failure. This is what `huwiya-api` calls internally, so Laravel's `auth:api` middleware responds with `401 Unauthorized` as usual.

### Exceptions — configuration and programmer errors

Exceptions are reserved for situations that indicate a bug or misconfiguration — the SDK fails loudly so you notice. All exceptions extend `Huwiya\Exceptions\HuwiyaException` (itself a `RuntimeException`).

| Exception                      | Thrown when                                                                                                                                        |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `InvalidGuardException`        | `Huwiya::redirect()` received an empty, unknown, or non-`huwiya-web` guard name.                                                                   |
| `AuthConfigurationException`   | `auth.providers.{provider}.model` is missing, or the model does not use `InteractsWithHuwiya`.                                                     |
| `InvalidStateException`        | The OAuth session payload is missing/malformed, `state` does not match, or `code` is absent. Caught by the callback and converted to `400`.        |
| `TokenExchangeException`       | The token endpoint timed out, returned a non-2xx response, or produced an unusable body. Caught by the callback and converted to `502`.            |
| `HuwiyaUserNotFoundException`  | Auto-registration is disabled and the authenticated subject has no local row. Caught by the callback and converted to `403`.                       |

### Callback HTTP status codes

The callback route translates every failure into a generic HTTP status — no stack traces, no internal detail leaks to the browser:

| Status | Meaning                                                                                        |
| ------ | ---------------------------------------------------------------------------------------------- |
| `400`  | Invalid or missing `state`, missing `code`, malformed session payload.                         |
| `401`  | Token failed signature, issuer, audience, or expiry validation.                                |
| `403`  | Auto-registration is disabled and the user has no matching local row.                          |
| `429`  | Rate limiter exceeded (default: 30 callbacks per minute per IP).                               |
| `502`  | Token endpoint unreachable, returned a non-2xx response, or returned an unusable token.        |

Override the rate limit via `config('huwiya.callback_middleware')` — see [Configuration reference](#configuration-reference).

## Logging

Set `HUWIYA_LOG_CHANNEL` to any channel configured in `config/logging.php` to receive warnings for JWKS fetch failures, signature mismatches, token-exchange errors, and authorization denials:

```dotenv
HUWIYA_LOG_CHANNEL=huwiya
```

Secrets (access tokens, client secrets) and response bodies are never logged. When the variable is unset, logging is a no-op.

## Security

The SDK is designed to be secure by default:

- **Signature verification** is on by default. Keys are fetched from the IdP's JWKS endpoint and matched by `kid`. The cache is invalidated under a short lock when an unknown `kid` is encountered, preventing a thundering herd during key rotation.
- **Algorithm pinning.** The expected JWT algorithm is pinned to `RS256`. Downgrade attacks using `alg:none` or `HS256` are rejected.
- **Claim validation.** Issuer (`iss`) and audience (`aud`) claims are validated by default.
- **OAuth state** is required on the callback, compared with `hash_equals()` (timing-safe), and consumed single-use via `pull`.
- **Guard binding.** The target guard is chosen by your server-side code when you call `Huwiya::redirect($guard)` and travels with the state in one atomic session entry. The callback re-validates the bound guard before calling `login()` — the guard cannot be influenced by user input, and a guard removed or altered between the redirect and the callback fails the request rather than logging into an unintended guard.
- **HTTP timeouts.** Outbound HTTP calls to the IdP (token exchange, JWKS fetch) are bounded by `config('huwiya.http_timeout')`. A hung IdP cannot tie up a worker indefinitely.
- **Rate limiting.** The `/huwiya/callback` route is limited to 30 requests per minute per IP out of the box.
- **Session fixation** is prevented by regenerating the session ID after a successful login.
- **Strict base64url decoding** is applied to every JWT segment — malformed inputs are rejected early.

If you discover a security vulnerability, please email **info@hitaqnia.com** rather than opening a public issue.

## Configuration reference

All settings are defined in `config/huwiya.php`.

| Key                   | Environment variable         | Default                                       | Description                                                                        |
| --------------------- | ---------------------------- | --------------------------------------------- | ---------------------------------------------------------------------------------- |
| `url`                 | `HUWIYA_URL`                 | `https://huwiya.id`                           | IdP base URL.                                                                      |
| `project_id`          | `HUWIYA_PROJECT_ID`          | *(required)*                                  | Project grouping OAuth clients. Used as the expected `aud` claim.                  |
| `client_id`           | `HUWIYA_CLIENT_ID`           | *(required)*                                  | OAuth client ID.                                                                   |
| `client_secret`       | `HUWIYA_CLIENT_SECRET`       | *(required)*                                  | OAuth client secret.                                                               |
| `redirect_uri`        | `HUWIYA_REDIRECT_URI`        | `{APP_URL}/huwiya/callback`                   | OAuth redirect URI. Must be registered with the IdP.                               |
| `stateful`            | `HUWIYA_STATEFUL_DOMAINS`    | localhost + `APP_URL` host + `FRONTEND_URL`   | Domains that receive session-based authentication via the stateful middleware.     |
| `auth_method`         | `HUWIYA_AUTH_METHOD`         | `basic`                                       | Client authentication method at the token endpoint: `basic` or `body`.             |
| `verify_signature`    | `HUWIYA_VERIFY_SIGNATURE`    | `true`                                        | Disable only for local development. **Always on in production.**                   |
| `algorithm`           | `HUWIYA_ALGORITHM`           | `RS256`                                       | Expected JWT signing algorithm. Mismatches are rejected.                           |
| `jwks_uri`            | `HUWIYA_JWKS_URI`            | `{url}/{project_id}/.well-known/jwks.json`    | Override if the IdP serves keys from a different location.                         |
| `leeway`              | `HUWIYA_TOKEN_LEEWAY`        | `60`                                          | Clock-skew tolerance for the `exp` claim, in seconds.                              |
| `validate_issuer`     | `HUWIYA_VALIDATE_ISSUER`     | `true`                                        | Require the `iss` claim to match `url`.                                            |
| `validate_audience`   | `HUWIYA_VALIDATE_AUDIENCE`   | `true`                                        | Require the `aud` claim to match `project_id`.                                     |
| `http_timeout`        | `HUWIYA_HTTP_TIMEOUT`        | `10`                                          | Timeout in seconds for outbound HTTP calls (token exchange, JWKS fetch).           |
| `home`                | `HUWIYA_HOME`                | `/`                                           | Fallback post-login URL when no intended URL was passed to `Huwiya::redirect()`.   |
| `callback_middleware` | *(not env-driven)*           | `['web', 'throttle:huwiya-callback']`         | Middleware stack for `/huwiya/callback`. Default rate limit is 30/min/IP.          |
| `log_channel`         | `HUWIYA_LOG_CHANNEL`         | `null`                                        | Log channel for diagnostics. Leave unset to disable logging.                       |

## Contributing

Bug reports, feature requests, and pull requests are welcome on [GitHub](https://github.com/hitaqnia/huwiya-laravel). Please run the test suite before submitting a pull request:

```bash
composer test
```

## License

Released under the [MIT License](LICENSE).
