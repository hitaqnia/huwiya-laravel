# Huwiya SDK for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hitaqnia/huwiya-laravel.svg?style=flat-square)](https://packagist.org/packages/hitaqnia/huwiya-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/hitaqnia/huwiya-laravel.svg?style=flat-square)](https://packagist.org/packages/hitaqnia/huwiya-laravel)
[![License](https://img.shields.io/packagist/l/hitaqnia/huwiya-laravel.svg?style=flat-square)](LICENSE)

The official Laravel SDK for the [Huwiya](https://huwiya.id) Identity Provider. It ships two first-class authentication drivers that integrate with Laravel's native guard system:

- **`huwiya-web`** — OAuth 2.0 Authorization Code flow for session-based web applications.
- **`huwiya-api`** — JWT Bearer authentication for stateless APIs.

## Table of Contents

- [Quick Start](#quick-start)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Preparing the User Model](#preparing-the-user-model)
  - [Registering Guards](#registering-guards)
  - [Initiating a Login](#initiating-a-login)
  - [Callback Route](#callback-route)
- [How It Works](#how-it-works)
  - [Web Flow](#web-flow)
  - [API Flow](#api-flow)
  - [SPA / First-Party Frontend](#spa--first-party-frontend)
- [Customization](#customization)
  - [User Mapping](#user-mapping)
  - [User Lookup](#user-lookup)
  - [User Creation](#user-creation)
  - [User Update](#user-update)
  - [Lifecycle Hooks](#lifecycle-hooks)
  - [Multiple Guards](#multiple-guards)
  - [Authorization Denial Handler](#authorization-denial-handler)
  - [Stateful Middleware](#stateful-middleware)
- [Invitations](#invitations)
- [Events](#events)
- [Testing Support](#testing-support)
- [Errors](#errors)
- [Logging](#logging)
- [Security](#security)
- [Configuration Reference](#configuration-reference)
- [Contributing](#contributing)
- [License](#license)

## Quick Start

```bash
composer require hitaqnia/huwiya-laravel

# Publish config and the users migration stub
php artisan vendor:publish --tag=huwiya-config
php artisan vendor:publish --tag=huwiya-migrations

# Edit the generated migration if you want to rename columns or skip fields,
# then run it:
php artisan migrate
```

Add credentials to `.env`:

```dotenv
HUWIYA_PROJECT_ID=your-project-id
HUWIYA_CLIENT_ID=your-client-id
HUWIYA_CLIENT_SECRET=your-client-secret
```

Add the trait to your `User` model and switch your guard to `huwiya-web`:

```php
// app/Models/User.php
use Huwiya\InteractsWithHuwiya;

class User extends Authenticatable { use InteractsWithHuwiya; }

// config/auth.php
'guards' => [
    'web' => ['driver' => 'huwiya-web', 'provider' => 'users'],
],
```

Wire a login route that starts the OAuth flow:

```php
use Huwiya\Facades\Huwiya;

Route::get('/login', fn () => Huwiya::redirect('web'))->name('login');
```

That's the whole setup. The SDK auto-registers `/huwiya/callback` behind a rate limiter (30 req/min/IP by default).

> **Invite-only?** Swap `InteractsWithHuwiya` for `InteractsWithHuwiyaAsInviteOnly` on your User model. Pre-seed rows with `phone` (no `huwiya_id`); the SDK claims them on first login. See [Invitations](#invitations).

## Requirements

| Dependency | Version |
| ---------- | ------- |
| PHP        | `^8.3`  |
| Laravel    | `^13.0` |

## Installation

Install the package via Composer:

```bash
composer require hitaqnia/huwiya-laravel
```

The service provider is registered automatically through Laravel's package discovery.

Optionally, publish the configuration file:

```bash
php artisan vendor:publish --tag="huwiya-config"
```

## Configuration

Add your Huwiya credentials to your `.env` file:

```dotenv
HUWIYA_PROJECT_ID=your-project-id
HUWIYA_CLIENT_ID=your-client-id
HUWIYA_CLIENT_SECRET=your-client-secret
```

All other settings fall back to sensible defaults, including the IdP base URL (`https://huwiya.id`), the redirect URI (`{APP_URL}/huwiya/callback`), the JWT algorithm (`RS256`), and clock-skew tolerance. Review the [Configuration Reference](#configuration-reference) to override any of them.

> **Note:** the default redirect URI is derived from `APP_URL`. Ensure `APP_URL` matches the host registered with the IdP, or set `HUWIYA_REDIRECT_URI` explicitly.

## Usage

### Preparing the User Model

Every authenticated user is identified by a `huwiya_id` column, which stores the `id` claim (a ULID) issued by the IdP. The package ships a Blueprint macro that creates this column with the correct type and constraints:

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->huwiyaIdentifier();
    $table->timestamps();
});
```

> **Identity lives on the IdP.** The IdP issues the `huwiya_id` (ULID) as the stable identifier and includes `phone`, `email`, and `name` as claims in every token. Preference claims (`locale`, `zoneinfo`, `theme`) are optional and default to empty strings when absent. The published migration creates columns for all of these; override `$huwiyaFieldsMap` on your User model to rename or disable any field. See [User Mapping](#user-mapping).

The macro defaults to a column named `huwiya_id` (nullable unique ULID — nullable so pre-seeded invitation rows can live in the same table before they're claimed). You may pass a custom name — `$table->huwiyaIdentifier('sso_id')` — as long as it matches the column returned by `getHuwiyaIdentifierColumn()` on your model.

For a full user schema you'll typically use the higher-level `huwiyaFields()` macro, which reads your model's `$huwiyaFieldsMap` and creates exactly the columns you've declared:

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->huwiyaFields(User::huwiyaFieldsSchema());
    $table->timestamps();
});
```

Next, add the `InteractsWithHuwiya` trait to your `User` model:

```php
use Huwiya\InteractsWithHuwiya;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use InteractsWithHuwiya;

    protected $fillable = ['name'];
}
```

> **The trait is mandatory.** Both guards throw `Huwiya\Exceptions\AuthConfigurationException` when resolving a user from a model that does not use it.

At boot time, the trait appends the Huwiya identifier column to the model's `$fillable` array automatically. Your own `$fillable` entries are preserved, and the merge is skipped when the model is fully guarded.

### Registering Guards

Switch your web and/or API guards in `config/auth.php` to the drivers provided by the package:

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

### Initiating a Login

The SDK does **not** ship a bundled redirect route. You initiate the OAuth flow by calling `Huwiya::redirect($guard)` from a route you own, passing the guard the user should be logged into:

```php
use Huwiya\Facades\Huwiya;

Route::get('/login', fn () => Huwiya::redirect('web'))->name('login');
Route::get('/admin/login', fn () => Huwiya::redirect('admin'))->name('admin.login');
```

The guard name must be a PHP literal in your own code. This keeps the choice of guard entirely server-side — users cannot influence it by tampering with a query string or form field.

`Huwiya::redirect()` validates the guard (it must be registered with the `huwiya-web` driver and point at a provider whose model uses `InteractsWithHuwiya`), stores the guard atomically alongside the OAuth `state` in the session, and returns a `RedirectResponse` to the IdP's authorize endpoint. Passing an empty, unknown, or non-`huwiya-web` guard throws `Huwiya\Exceptions\InvalidGuardException`.

### Callback Route

A single callback route is registered automatically at the fixed path `/huwiya/callback` (name: `huwiya.callback`). Register this URL with the IdP as an allowed redirect URI, or override it via `HUWIYA_REDIRECT_URI` to match a custom host. Authenticated API requests are handled transparently by the `huwiya-api` guard — no additional routes are required.

## How It Works

### Web Flow

1. The user hits a route in your application that calls `Huwiya::redirect($guard)`. The package validates that the named guard uses the `huwiya-web` driver, generates a cryptographically random `state`, stores `['state' => ..., 'guard' => ...]` atomically under the session key `huwiya.oauth`, and redirects to the IdP.
2. The IdP redirects back to `/huwiya/callback`. The package pulls (and clears) the bound session payload, verifies the `state` with a timing-safe comparison, re-validates the guard against the current auth configuration, exchanges the authorization code for a JWT at `{url}/oauth/token`, decodes the claims, and invokes `User::findOrCreateFromHuwiya($claims, $guard)`. This triggers the full [lifecycle](#customization) — lookup, create-or-update, hooks, and [events](#events).
3. The user is authenticated into the guard that was bound at redirect time. Subsequent requests are authenticated by that `huwiya-web` guard.

After a successful login, the callback issues `redirect()->intended('/')`, so any `url.intended` value your middleware sets will be honoured.

### API Flow

For each request, the `huwiya-api` guard performs the following steps:

1. Reads the `Authorization: Bearer <jwt>` header.
2. Verifies the signature against the IdP's JWKS, cached for one hour under a key derived from the JWKS URI. The cache is invalidated automatically when an unknown `kid` is encountered, supporting seamless key rotation.
3. Validates the `alg`, `exp` (with leeway), `iss`, and `aud` claims.
4. Invokes `User::findOrCreateFromHuwiya($claims, $guard)`, triggering the same [lifecycle](#customization) and [events](#events) as the web flow.
5. Attaches the decoded `TokenClaims` instance to `$user->huwiyaToken`.

The flow is fully stateless — no session is created or read.

### SPA / First-Party Frontend

The stateful middleware required for cookie-based SPA authentication is **not** registered automatically. Opt in by appending it to your global middleware stack in `bootstrap/app.php`:

```php
use Huwiya\Http\Middleware\EnsureFrontendRequestsAreStateful;

->withMiddleware(function (Middleware $middleware) {
    $middleware->append(EnsureFrontendRequestsAreStateful::class);
})
```

Configure `HUWIYA_STATEFUL_DOMAINS` with a comma-separated list of trusted frontend origins. Localhost variants, the host from `APP_URL`, and `FRONTEND_URL` are included by default.

## Customization

The `InteractsWithHuwiya` trait provides sensible defaults that work out of the box. Every step of the authentication lifecycle is exposed as an individual method you can override on your model — no subclassing controllers or guards required. Customization is per-model, so `User` and `Admin` can each have their own rules.

The lifecycle runs in this order:

```
resolveHuwiyaUser()
    └─ newHuwiyaQuery() → huwiyaQueryForClaims()
         │
         ├─ User found → beforeHuwiyaUpdate() → updateHuwiyaUser() → afterHuwiyaUpdate()
         │
         └─ Not found  → shouldAutoRegister()
                           ├─ false → HuwiyaUserNotFoundException
                           └─ true  → beforeHuwiyaCreate() → createHuwiyaUser() → afterHuwiyaCreate()
```

### User Mapping

**Identifier column.** Override the column name on both the model and the migration:

```php
// On the User model:
public function getHuwiyaIdentifierColumn(): string
{
    return 'sso_id';
}

// In the migration:
$table->huwiyaIdentifier('sso_id');
```

**Attribute mapping.** By default, the trait only persists `name`. Because identity — including `phone` and `email` — is owned by the IdP, the recommended approach is to keep the local projection minimal (ideally just `huwiya_id`) and fetch anything else from Huwiya on demand. If you do want to cache claim fields locally, override the two attribute methods. Any column you return here must already exist on your users table:

```php
use Huwiya\TokenClaims;

public function getHuwiyaCreateAttributes(TokenClaims $claims): array
{
    return [
        'name'     => $claims->name,
        'locale'   => $claims->locale,
        'timezone' => $claims->zoneinfo,
        'theme'    => $claims->theme,
    ];
}

public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
{
    return ['name' => $claims->name];
}
```

Return an empty array from `getHuwiyaUpdateAttributes()` to skip updates on re-login entirely. Return an empty array from `getHuwiyaCreateAttributes()` as well if you want to store nothing beyond `huwiya_id`.

> **Do not map `phone` or `email`.** These claims are not issued in the token — attempting to read `$claims->phone` or `$claims->email` will produce an undefined-property error. Query the IdP directly if you need them.

**Disable auto-registration.** By default, users that do not exist locally are created on first login. To reject unknown users:

```php
public function shouldAutoRegister(?TokenClaims $claims = null): bool
{
    return false;
}
```

The `$claims` argument gives you access to the incoming token, so you can conditionally allow registration (e.g. based on scopes or an invitation check):

```php
public function shouldAutoRegister(?TokenClaims $claims = null): bool
{
    return Invitation::where('huwiya_id', $claims?->id)
        ->whereNull('accepted_at')
        ->exists();
}
```

When disabled, unknown users cause the callback to throw `Huwiya\Exceptions\HuwiyaUserNotFoundException`. Handle the exception in your application's exception handler to redirect appropriately.

**Helpers.** The trait exposes the following methods:

```php
User::findByHuwiyaId('01HR...');                  // ?User
User::resolveHuwiyaUser($claims);                 // ?User — customizable lookup
User::findOrCreateFromHuwiya($claims, $guard);    // Called internally by the SDK
$user->huwiyaToken;                               // TokenClaims (API requests only)
```

### User Lookup

By default, users are matched by the `huwiya_id` column. Override `huwiyaQueryForClaims()` to customize how users are resolved — for example, matching by `huwiya_id` with a fallback to `email`:

```php
use Huwiya\TokenClaims;
use Illuminate\Database\Eloquent\Builder;

public function huwiyaQueryForClaims(Builder $query, TokenClaims $claims): Builder
{
    return $query->where(function (Builder $q) use ($claims) {
        $q->where('huwiya_id', $claims->id)
          ->orWhere('email', $claims->name);
    });
}
```

Override `newHuwiyaQuery()` to customize the base query — apply tenant scopes, include soft-deleted records, or eager-load relationships:

```php
public static function newHuwiyaQuery(): Builder
{
    return static::query()->withTrashed();
}
```

### User Creation

Override `createHuwiyaUser()` when you need creation logic beyond simple attribute mapping — assigning roles, attaching to a tenant, wrapping in a transaction:

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

The default `createHuwiyaUser()` delegates to `getHuwiyaCreateAttributes()` — override that if you only need to control the attribute map, or override `createHuwiyaUser()` itself for full control over the creation process.

### User Update

Override `updateHuwiyaUser()` when you need update logic beyond simple attribute mapping — syncing roles from scopes, conditionally skipping updates, or tracking last login:

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

The default `updateHuwiyaUser()` delegates to `getHuwiyaUpdateAttributes()` and skips the write when it returns an empty array.

### Lifecycle Hooks

The trait provides `before` and `after` hooks for both creation and update. These are simple no-op methods you can override for side effects without replacing the core create/update logic:

```php
use Huwiya\TokenClaims;

public function beforeHuwiyaCreate(TokenClaims $claims): void
{
    Log::info('Provisioning new user', ['huwiya_id' => $claims->id]);
}

public function afterHuwiyaCreate(TokenClaims $claims): void
{
    $this->notify(new WelcomeNotification);
}

public function beforeHuwiyaUpdate(TokenClaims $claims): void
{
    // Called before an existing user is updated on re-login.
}

public function afterHuwiyaUpdate(TokenClaims $claims): void
{
    // Called after an existing user has been updated on re-login.
}
```

For app-level side effects that should be decoupled from the model (sending queued emails, dispatching jobs, notifying external services), prefer [Events](#events) over hooks.

### Multiple Guards

You may register any number of guards using the `huwiya-web` and `huwiya-api` drivers — for example, separate `admin` and `api` guards backed by different providers:

```php
'guards' => [
    'web'   => ['driver' => 'huwiya-web', 'provider' => 'users'],
    'admin' => ['driver' => 'huwiya-web', 'provider' => 'admins'],
    'api'   => ['driver' => 'huwiya-api', 'provider' => 'users'],
],
```

The callback route is shared by every `huwiya-web` guard. The guard the user will be logged into is decided when you call `Huwiya::redirect($guard)`, and travels with the OAuth `state` in a single atomic session entry. Expose a separate login route per portal and pass the correct guard from each:

```php
use Huwiya\Facades\Huwiya;

Route::get('/login', fn () => Huwiya::redirect('web'));
Route::get('/admin/login', fn () => Huwiya::redirect('admin'));
```

Because the guard name is a server-side PHP literal in each route, users cannot log into a guard you did not intend. The callback re-validates the bound guard against the current auth config before calling `login()` — if the guard is removed or its driver is changed between the redirect and the callback, the request fails rather than silently falling through to another guard.

### Authorization Denial Handler

When the user denies consent at the IdP, the callback dispatches a configurable handler. The default redirects to `/`. Override it in a service provider's `boot()` method:

```php
use Huwiya\Facades\Huwiya;

public function boot(): void
{
    Huwiya::whenAuthorizationDenied(function (?string $error, ?string $description) {
        return redirect()
            ->route('login')
            ->with('error', $description ?? 'Authorization denied.');
    });
}
```

The callback may declare zero, one, or two parameters; the package inspects its arity and dispatches accordingly. The handler is stored in a container-scoped singleton, so it resets per request under Laravel Octane and other resident runtimes.

### Stateful Middleware

You may swap the cookie and CSRF middleware used by `EnsureFrontendRequestsAreStateful` via `config/huwiya.php`. These settings are consulted only when the middleware is explicitly registered.

```php
'middleware' => [
    'encrypt_cookies'     => \App\Http\Middleware\EncryptCookies::class,
    'validate_csrf_token' => \App\Http\Middleware\ValidateCsrfToken::class,
],
```

> **Session cookie hardening.** Earlier versions of this middleware mutated `session.http_only` and `session.same_site` at runtime. That was a footgun under long-running workers (Octane) and has been removed. Set these values in your `config/session.php` directly:
>
> ```php
> // config/session.php
> 'http_only' => true,
> 'same_site' => 'lax',
> ```

## Invitations

When invitations are enabled, the SDK falls back to a second lookup when a token's `huwiya_id` doesn't match any local user: it looks for a row with matching `phone` **and** a NULL `huwiya_id`. If found, that row is "claimed" — its `huwiya_id` is stamped from the token, other fields are synced, and the user logs in.

The common install is invite-only: pre-seed users by phone, no open auto-registration. The package ships a convenience trait that wires the two relevant switches:

```php
use Huwiya\InteractsWithHuwiyaAsInviteOnly;

class User extends Authenticatable
{
    use InteractsWithHuwiyaAsInviteOnly;
}

// Pre-seed rows:
User::create(['phone' => '+9647700000001', 'name' => 'Pending Invitee']);
```

Hybrid setups (both invitations and auto-registration on) are supported — override `invitationsEnabled()` to return `true` on your regular `InteractsWithHuwiya` model while leaving `shouldAutoRegister()` at its `true` default.

Two lifecycle events fire around a claim: `HuwiyaInvitationClaiming` (before) and `HuwiyaInvitationClaimed` (after). Use them to send welcome emails, mark admin-side workflows complete, or emit audit events.

## Events

The SDK dispatches lifecycle events during user resolution, creation, and update. Events are fired from inside the `InteractsWithHuwiya` trait, so both the web (OAuth callback) and API (JWT bearer) flows fire them automatically.

Every event carries the `TokenClaims`, the `Authenticatable` user (when available), and the guard name (e.g. `'web'`, `'admin'`, `'api'`).

| Event | Payload | Fired when |
| ----- | ------- | ---------- |
| `HuwiyaAuthenticating` | `$claims`, `$guard` | Before any database work. |
| `HuwiyaUserResolving` | `$claims`, `$query`, `$guard` | Before the lookup query executes. Listeners may further constrain `$query`. |
| `HuwiyaUserCreating` | `$claims`, `$guard` | Before a new user is created (after `beforeHuwiyaCreate()`). |
| `HuwiyaUserCreated` | `$claims`, `$user`, `$guard` | After a new user has been created (after `afterHuwiyaCreate()`). |
| `HuwiyaUserUpdating` | `$claims`, `$user`, `$guard` | Before an existing user is updated (after `beforeHuwiyaUpdate()`). |
| `HuwiyaUserUpdated` | `$claims`, `$user`, `$guard` | After an existing user has been updated (after `afterHuwiyaUpdate()`). |
| `HuwiyaAuthenticated` | `$claims`, `$user`, `$guard` | After the user has been resolved (created or updated). Fires on every authentication. |

All event classes live under the `Huwiya\Events` namespace.

**Example: send a welcome email on first login.**

```php
// app/Providers/AppServiceProvider.php (or EventServiceProvider)
use Huwiya\Events\HuwiyaUserCreated;
use App\Listeners\SendWelcomeEmail;

protected $listen = [
    HuwiyaUserCreated::class => [
        SendWelcomeEmail::class,
    ],
];
```

```php
// app/Listeners/SendWelcomeEmail.php
use Huwiya\Events\HuwiyaUserCreated;

class SendWelcomeEmail
{
    public function handle(HuwiyaUserCreated $event): void
    {
        Mail::to($event->user)->queue(new WelcomeMail($event->user));
    }
}
```

**Example: track last login timestamp on every sign-in.**

```php
use Huwiya\Events\HuwiyaAuthenticated;

class RecordLastLogin
{
    public function handle(HuwiyaAuthenticated $event): void
    {
        $event->user->update(['last_login_at' => now()]);
    }
}
```

**Example: add extra query constraints via a listener.**

```php
use Huwiya\Events\HuwiyaUserResolving;

class ScopeLookupToTenant
{
    public function handle(HuwiyaUserResolving $event): void
    {
        $event->query->where('tenant_id', session('tenant_id'));
    }
}
```

## Testing Support

The SDK provides a helper to authenticate a user within tests, bypassing the OAuth flow:

```php
use Huwiya\Facades\Huwiya;

Huwiya::actingAs($user, 'web');
Huwiya::actingAs($user, 'api');
```

The package's own test suite can be executed with:

```bash
composer test
```

## Errors

The SDK uses two error mechanisms, each suited to a different kind of failure:

1. **Token rejection uses the Result pattern** — an HTTP-received, third-party-signed blob being unacceptable (bad signature, wrong issuer, expired, malformed) is a routine domain outcome, not a programmer error. `Huwiya::decodeAndVerifyToken()` returns a `Huwiya\Support\Result<TokenClaims>`:

    ```php
    use Huwiya\Huwiya;
    use Huwiya\Support\TokenRejection;

    $result = Huwiya::decodeAndVerifyToken($jwt);

    if ($result->isSuccess()) {
        $claims = $result->getData();
        // ...
    } else {
        match ($result->getError()->getCode()) {
            TokenRejection::EXPIRED => /* 401, refresh */,
            TokenRejection::BAD_SIGNATURE,
            TokenRejection::BAD_ISSUER,
            TokenRejection::BAD_AUDIENCE => /* 401, reject */,
            TokenRejection::MALFORMED,
            TokenRejection::MISSING_CLAIMS => /* 400, bad input */,
            TokenRejection::JWKS_UNAVAILABLE,
            TokenRejection::UNKNOWN_KID,
            TokenRejection::UNSUPPORTED_KEY_TYPE => /* 502, IdP issue */,
        };
    }
    ```

    Callers that don't care about the reason (most notably guards) can use the convenience wrapper `Huwiya::tryDecodeAndVerifyToken()`, which returns `TokenClaims` on success or `null` on any failure. This is what `huwiya-api` calls internally, so `auth:api` middleware returns `401 Unauthorized` as usual.

2. **Exceptions are reserved for programmer and configuration errors** — things that should never happen in a working app and should fail loudly:

    | Exception                      | Thrown when                                                                                        |
    | ------------------------------ | -------------------------------------------------------------------------------------------------- |
    | `InvalidGuardException`        | `Huwiya::redirect()` received an empty, unknown, or non-`huwiya-web` guard name.                   |
    | `AuthConfigurationException`   | `auth.providers.{provider}.model` is missing, or the model does not use `InteractsWithHuwiya`.     |
    | `InvalidStateException`        | The OAuth callback session payload is missing/malformed, the returned `state` does not match, or the `code` parameter is absent. Caught by the callback controller and converted to `400`. |
    | `TokenExchangeException`       | The token endpoint timed out, returned a non-2xx response, or sent a body without `access_token`. Caught by the callback controller and converted to `502`. |
    | `HuwiyaUserNotFoundException`  | Auto-registration is disabled and no local user matches the incoming subject. Caught by the callback controller and converted to `403`. |

    All exceptions extend `Huwiya\Exceptions\HuwiyaException`, which in turn extends `\RuntimeException`.

### OAuth callback HTTP status codes

The callback route translates each failure class into a generic HTTP status — no stack traces, no internal detail leaks to the browser:

- `400 Bad Request` — invalid/missing state, missing code, malformed session payload
- `401 Unauthorized` — token failed signature, issuer, audience, or expiry checks
- `403 Forbidden` — auto-registration is off and the authenticated user has no matching local row
- `502 Bad Gateway` — token endpoint unreachable, non-2xx, or returned an unusable token
- `429 Too Many Requests` — rate limiter exceeded (default: 30 callbacks per minute per IP)

Override the rate limit via `config('huwiya.callback_middleware')` — see [Configuration Reference](#configuration-reference).

## Logging

Set `HUWIYA_LOG_CHANNEL` to any channel configured in `config/logging.php` to receive warnings for JWKS fetch failures, signature mismatches, token-exchange errors, and authorization denials:

```dotenv
HUWIYA_LOG_CHANNEL=huwiya
```

Secrets (access tokens, client secrets) are never logged, and response bodies are truncated to 200 characters. When the variable is unset, logging is a no-op.

## Security

The SDK is designed to be secure by default:

- **Signature verification** is on by default. Keys are fetched from the IdP's JWKS endpoint and matched by `kid`. The cache is invalidated automatically on unknown `kid` to support key rotation.
- **Algorithm pinning.** The expected JWT algorithm is pinned to `RS256`. Downgrade attacks using `alg:none` or `HS256` are rejected.
- **Claim validation.** Issuer (`iss`) and audience (`aud`) claims are validated by default.
- **OAuth state** is required on the callback, compared using `hash_equals()` (timing-safe), and cleared from the session after use (single-use via `pull`, not `get`).
- **Guard binding.** The target guard is chosen by your own server-side code when you call `Huwiya::redirect($guard)` and is stored atomically with the state in one session entry. The callback re-validates that the bound guard still uses the `huwiya-web` driver before authenticating — an attacker cannot influence the guard via query strings or cookies, and a guard removed or altered between the two requests causes the flow to fail instead of logging into an unintended guard.
- **Strict base64url decoding** is applied to every JWT segment — malformed inputs are rejected early.
- **Session cookies** are marked `HttpOnly` and `SameSite=Lax` when the stateful middleware is active.
- **Session fixation** is prevented by regenerating the session ID after a successful login.

If you discover a security vulnerability, please email **info@hitaqnia.com** rather than opening a public issue.

## Configuration Reference

All settings are defined in `config/huwiya.php`.

| Key                   | Environment Variable        | Default                                       | Description                                                                        |
| --------------------- | --------------------------- | --------------------------------------------- | ---------------------------------------------------------------------------------- |
| `url`                 | `HUWIYA_URL`                | `https://huwiya.id`                           | IdP base URL.                                                                      |
| `project_id`          | `HUWIYA_PROJECT_ID`         | *(required)*                                  | Project grouping OAuth clients. Used as the expected `aud` claim.                  |
| `client_id`           | `HUWIYA_CLIENT_ID`          | *(required)*                                  | OAuth client ID.                                                                   |
| `client_secret`       | `HUWIYA_CLIENT_SECRET`      | *(required)*                                  | OAuth client secret.                                                               |
| `redirect_uri`        | `HUWIYA_REDIRECT_URI`       | `{APP_URL}/huwiya/callback`                   | OAuth redirect URI. Must be registered on the IdP.                                 |
| `stateful`            | `HUWIYA_STATEFUL_DOMAINS`   | localhost + `APP_URL` host + `FRONTEND_URL`   | Domains that receive session-based authentication via the stateful middleware.     |
| `auth_method`         | `HUWIYA_AUTH_METHOD`        | `basic`                                       | Client authentication method at the token endpoint: `basic` or `body`.             |
| `verify_signature`    | `HUWIYA_VERIFY_SIGNATURE`   | `true`                                        | Disable only for local development. **Always on in production.**                   |
| `algorithm`           | `HUWIYA_ALGORITHM`          | `RS256`                                       | Expected JWT signing algorithm. Mismatches are rejected.                           |
| `jwks_uri`            | `HUWIYA_JWKS_URI`           | `{url}/{project_id}/.well-known/jwks.json`    | Override if the IdP serves keys from a different location.                         |
| `leeway`              | `HUWIYA_TOKEN_LEEWAY`       | `60`                                          | Clock-skew tolerance for the `exp` claim, in seconds.                              |
| `validate_issuer`     | `HUWIYA_VALIDATE_ISSUER`    | `true`                                        | Require the `iss` claim to match `url`.                                            |
| `validate_audience`   | `HUWIYA_VALIDATE_AUDIENCE`  | `true`                                        | Require the `aud` claim to match `project_id`.                                     |
| `log_channel`         | `HUWIYA_LOG_CHANNEL`        | `null`                                        | Log channel for diagnostics. Leave unset to disable logging.                       |
| `http_timeout`        | `HUWIYA_HTTP_TIMEOUT`       | `10`                                          | Timeout in seconds for outbound HTTP calls (token exchange, JWKS fetch).           |
| `home`                | `HUWIYA_HOME`               | `/`                                           | Fallback post-login URL when no intended URL was passed to `Huwiya::redirect()`.   |
| `callback_middleware` | *(not env-driven)*          | `['web', 'throttle:huwiya-callback']`         | Middleware stack for `/huwiya/callback`. Default rate limit is 30/min/IP.          |

## Contributing

Bug reports, feature requests, and pull requests are welcome on [GitHub](https://github.com/hitaqnia/huwiya-laravel). Please run the test suite before submitting a pull request:

```bash
composer test
```

## License

Released under the [MIT License](LICENSE).
