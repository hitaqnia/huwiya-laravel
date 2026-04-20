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
  - [Field mapping](#field-mapping)
  - [User lookup](#user-lookup)
  - [User creation](#user-creation)
  - [User update](#user-update)
  - [Lifecycle hooks](#lifecycle-hooks)
  - [Multiple guards](#multiple-guards)
  - [Authorization denial](#authorization-denial)
  - [Stateful middleware for SPAs](#stateful-middleware-for-spas)
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

The service provider is registered automatically through Laravel's package discovery. Publish the configuration file if you need to customize anything beyond the environment variables:

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

The SDK does not ship a migration. It provides two Blueprint macros you compose into your own `users` table migration.

**`huwiyaIdentifier()`** — creates the Huwiya subject column (nullable, unique, ULID). The column is nullable so invitation rows can exist before they are claimed.

**`huwiyaFields()`** — a one-call bundler that creates every column declared in your model's `$huwiyaFieldsMap`. The map is the single source of truth: the schema and the runtime both read it, so renaming or disabling a field is a one-line change.

Recommended migration:

```php
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->huwiyaFields(User::huwiyaFieldsSchema());
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

With the default field map, this creates:

| Column      | Type            | Constraints           |
| ----------- | --------------- | --------------------- |
| `huwiya_id` | `ulid`          | nullable, unique      |
| `phone`     | `string`        | unique                |
| `email`     | `string`        | nullable, unique      |
| `name`      | `string`        | nullable              |
| `locale`    | `string(10)`    | nullable              |
| `zoneinfo`  | `string(64)`    | nullable              |
| `theme`     | `string(16)`    | nullable              |

If you only want the identifier column (for example when you don't need any claim data cached locally), call the lower-level macro directly:

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->huwiyaIdentifier();           // nullable, unique ulid
    $table->string('phone')->unique();    // your own columns
    $table->timestamps();
});
```

To disable a default field, rename a column, or declare columns your IdP tenant adds, customize `$huwiyaFieldsMap` on your model — see [Field mapping](#field-mapping).

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

The SDK does not ship a login route. Start the OAuth flow from a route you own by calling `Huwiya::redirect($guard)` and passing the guard the user should end up logged into:

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

The `InteractsWithHuwiya` trait ships sensible defaults. Every step of the authentication lifecycle is exposed as an individual method you can override on your model — no subclassing of controllers or guards is required. Customization is per-model, so `User` and `Admin` can each have their own rules.

Lifecycle order:

```
resolveHuwiyaUser()
    └─ newHuwiyaQuery() → huwiyaQueryForClaims()
         │
         ├─ User found      → beforeHuwiyaUpdate() → updateHuwiyaUser() → afterHuwiyaUpdate()
         │   (invitation?   → HuwiyaInvitationClaiming / HuwiyaInvitationClaimed events)
         │
         └─ Not found       → shouldAutoRegister()
                              ├─ false → HuwiyaUserNotFoundException
                              └─ true  → beforeHuwiyaCreate() → createHuwiyaUser() → afterHuwiyaCreate()
```

### Field mapping

`$huwiyaFieldsMap` on your model is a single `claim key => column name` array consumed by both the `huwiyaFields()` migration macro and the runtime sync logic. Keeping a single source of truth makes renames safe.

The trait default covers every claim Huwiya issues:

```php
protected array $huwiyaFieldsMap = [
    'phone'    => 'phone',
    'email'    => 'email',
    'name'     => 'name',
    'locale'   => 'locale',
    'zoneinfo' => 'zoneinfo',
    'theme'    => 'theme',
];
// huwiya_id is always included.
```

Override on your model to disable fields (omit the key or set it to `false`) or rename columns (change the value). The three shapes you'll actually write:

```php
// Identity only — id + phone, nothing else cached locally.
protected array $huwiyaFieldsMap = [
    'phone' => 'phone',
];
```

```php
// Rename a column — for example, phone_number instead of phone.
protected array $huwiyaFieldsMap = [
    'phone' => 'phone_number',
    'email' => 'email',
    'name'  => 'full_name',
];
```

```php
// Skip preference claims entirely.
protected array $huwiyaFieldsMap = [
    'phone' => 'phone',
    'email' => 'email',
    'name'  => 'name',
    // locale, zoneinfo, theme omitted → not stored, not written
];
```

**Identifier column name.** Override both the model and the macro call:

```php
// Model
public function getHuwiyaIdentifierColumn(): string
{
    return 'sso_id';
}

// Migration
$table->huwiyaIdentifier('sso_id');
// or, because huwiyaFields() reads getHuwiyaIdentifierColumn():
$table->huwiyaFields(User::huwiyaFieldsSchema());
```

**Advanced mapping.** If you need claim-to-column transformations beyond rename (e.g. lowercasing email, deriving a column from multiple claims), override `getHuwiyaCreateAttributes()` and `getHuwiyaUpdateAttributes()` directly:

```php
public function getHuwiyaCreateAttributes(\Huwiya\TokenClaims $claims): array
{
    return [
        'email'       => strtolower($claims->email),
        'name'        => $claims->name,
        'timezone'    => $claims->zoneinfo ?: config('app.timezone'),
    ];
}
```

### User lookup

By default, users are matched by `huwiya_id`. Override `huwiyaQueryForClaims()` to customize — for example, to also fall back to email:

```php
use Huwiya\TokenClaims;
use Illuminate\Database\Eloquent\Builder;

public function huwiyaQueryForClaims(Builder $query, TokenClaims $claims): Builder
{
    return parent::huwiyaQueryForClaims($query, $claims)
        ->orWhere(fn (Builder $q) => $q->whereNull('huwiya_id')->where('email', $claims->email));
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

The default implementation delegates to `getHuwiyaCreateAttributes()` — override that if you only need to shape the attribute array, or override `createHuwiyaUser()` itself for full control.

### User update

Override `updateHuwiyaUser()` when you need update logic beyond simple attribute sync — syncing roles from scopes, conditionally skipping updates, tracking last login:

```php
use Huwiya\TokenClaims;

public function updateHuwiyaUser(TokenClaims $claims): void
{
    $this->update(array_merge(
        $this->getHuwiyaUpdateAttributes($claims, $this->isHuwiyaInvitationClaim()),
        ['last_login_at' => now()],
    ));

    $this->syncRolesFromScopes($claims->scopes);
}
```

The default implementation delegates to `getHuwiyaUpdateAttributes()` and skips the write when it returns an empty array.

### Lifecycle hooks

The trait provides `before` and `after` hooks for both creation and update. These are no-op methods you can override for side effects without replacing the core logic:

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
```

For decoupled side effects (queued emails, dispatched jobs, external notifications), prefer [events](#events) over hooks.

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

Because the guard name is a server-side literal, users cannot influence it via query string or cookie. The callback re-validates the bound guard against the current auth config before calling `login()` — if the guard is removed or its driver is changed between the redirect and the callback, the request fails rather than silently logging into an unintended guard.

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

## Invitations

When invitations are enabled, the SDK falls back to a second lookup when the token's `huwiya_id` matches no local user: it looks for a row with matching `phone` and a NULL `huwiya_id`. If found, that row is "claimed" — the `huwiya_id` column is stamped from the token, other fields are synced, and the user is logged in.

The common install is **invite-only**: pre-seed users by phone, reject anyone who is not invited. The SDK ships a convenience trait that wires the two relevant switches:

```php
use Huwiya\InteractsWithHuwiyaAsInviteOnly;

class User extends Authenticatable
{
    use InteractsWithHuwiyaAsInviteOnly;
}

// Pre-seed an invitation:
User::create(['phone' => '+9647700000001', 'name' => 'Pending Invitee']);
```

Hybrid setups (both invitations and auto-registration on) are supported — override `invitationsEnabled()` on your regular `InteractsWithHuwiya` model to return `true` while leaving `shouldAutoRegister()` at its `true` default.

Two lifecycle events fire around a claim: `HuwiyaInvitationClaiming` (before) and `HuwiyaInvitationClaimed` (after). Use them to send welcome emails, mark admin-side workflows complete, or emit audit events.

Under concurrent first-logins for the same phone — two browser tabs racing to claim — the SDK retries once on unique-constraint violation and returns the row that won the race.

## Events

The SDK dispatches lifecycle events from inside the `InteractsWithHuwiya` trait, so both the web (OAuth callback) and API (JWT bearer) flows fire them consistently. Every event carries the `TokenClaims`, the `Authenticatable` user (when available), and the guard name.

| Event                        | Payload                          | Fired when                                                                               |
| ---------------------------- | -------------------------------- | ---------------------------------------------------------------------------------------- |
| `HuwiyaAuthenticating`       | `$claims`, `$guard`              | Before any database work.                                                                |
| `HuwiyaUserResolving`        | `$claims`, `$query`, `$guard`    | Before the lookup query executes. Listeners may further constrain `$query`.              |
| `HuwiyaUserCreating`         | `$claims`, `$guard`              | Before a new user is created (after `beforeHuwiyaCreate()`).                             |
| `HuwiyaUserCreated`          | `$claims`, `$user`, `$guard`     | After a new user has been created (after `afterHuwiyaCreate()`).                         |
| `HuwiyaUserUpdating`         | `$claims`, `$user`, `$guard`     | Before an existing user is updated (after `beforeHuwiyaUpdate()`).                       |
| `HuwiyaUserUpdated`          | `$claims`, `$user`, `$guard`     | After an existing user has been updated (after `afterHuwiyaUpdate()`).                   |
| `HuwiyaInvitationClaiming`   | `$claims`, `$user`, `$guard`     | Before an invitation row is claimed. Fires around the same update that stamps `huwiya_id`. |
| `HuwiyaInvitationClaimed`    | `$claims`, `$user`, `$guard`     | After an invitation row has been claimed.                                                |
| `HuwiyaAuthenticated`        | `$claims`, `$user`, `$guard`     | After the user has been resolved (created, updated, or claimed). Fires on every auth.    |

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
