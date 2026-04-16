# Huwiya SDK for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hitaqnia/huwiya-laravel.svg?style=flat-square)](https://packagist.org/packages/hitaqnia/huwiya-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/hitaqnia/huwiya-laravel.svg?style=flat-square)](https://packagist.org/packages/hitaqnia/huwiya-laravel)
[![License](https://img.shields.io/packagist/l/hitaqnia/huwiya-laravel.svg?style=flat-square)](LICENSE)

The official Laravel SDK for the [Huwiya](https://huwiya.id) Identity Provider. It ships two first-class authentication drivers that integrate with Laravel's native guard system:

- **`huwiya-web`** — OAuth 2.0 Authorization Code flow for session-based web applications.
- **`huwiya-api`** — JWT Bearer authentication for stateless APIs.

## Table of Contents

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
  - [Multiple Guards](#multiple-guards)
  - [Authorization Denial Handler](#authorization-denial-handler)
  - [Stateful Middleware](#stateful-middleware)
- [Testing Support](#testing-support)
- [Exceptions](#exceptions)
- [Logging](#logging)
- [Security](#security)
- [Configuration Reference](#configuration-reference)
- [Contributing](#contributing)
- [License](#license)

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

> **Identity lives on the IdP.** The `huwiya_id` column is the only attribute the package requires on your users table. Contact details such as `phone` and `email` are **not** part of the token claims, and the IdP does **not** expose them to third-party applications at all — they remain visible only to the user themselves. Design your app around the `huwiya_id` as the sole identifier, and collect any additional details you need directly from the user. Keeping your users table minimal avoids drift between your app and the identity source of truth.

The macro defaults to a column named `huwiya_id`. You may pass a custom name — `$table->huwiyaIdentifier('sso_id')` — as long as it matches the column returned by `getHuwiyaIdentifierColumn()` on your model. The unique index is required: the package relies on it to prevent duplicate user rows under concurrent first-login requests.

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
2. The IdP redirects back to `/huwiya/callback`. The package pulls (and clears) the bound session payload, verifies the `state` with a timing-safe comparison, re-validates the guard against the current auth configuration, exchanges the authorization code for a JWT at `{url}/oauth/token`, decodes the claims, and invokes `User::findOrCreateFromHuwiya($claims)`.
3. The user is authenticated into the guard that was bound at redirect time. Subsequent requests are authenticated by that `huwiya-web` guard.

After a successful login, the callback issues `redirect()->intended('/')`, so any `url.intended` value your middleware sets will be honoured.

### API Flow

For each request, the `huwiya-api` guard performs the following steps:

1. Reads the `Authorization: Bearer <jwt>` header.
2. Verifies the signature against the IdP's JWKS, cached for one hour under a key derived from the JWKS URI. The cache is invalidated automatically when an unknown `kid` is encountered, supporting seamless key rotation.
3. Validates the `alg`, `exp` (with leeway), `iss`, and `aud` claims.
4. Invokes `User::findOrCreateFromHuwiya($claims)`.
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

### User Mapping

The `InteractsWithHuwiya` trait provides sensible defaults for mapping Huwiya claims onto your model. Each method is overridable.

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

**Attribute mapping.** By default, the trait only persists `name`. Because identity — including `phone` and `email` — is owned by the IdP, the recommended approach is to keep the local projection minimal (ideally just `huwiya_id`) and fetch anything else from Huwiya on demand. If you do want to cache claim fields locally, override the two methods below. Any column you return here must already exist on your users table:

```php
use Huwiya\TokenClaims;

public function getHuwiyaCreateAttributes(TokenClaims $claims): array
{
    return [
        'name'     => $claims->name,
        'locale'   => $claims->locale,
        'timezone' => $claims->zoneinfo,
        'theme'    => $claims->theme,
        'role'     => 'member',
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
public function shouldAutoRegister(): bool
{
    return false;
}
```

When disabled, unknown users cause the callback to throw `Huwiya\Exceptions\HuwiyaUserNotFoundException`. Handle the exception in your application's exception handler to redirect appropriately.

**Helpers.** The trait exposes the following methods:

```php
User::findByHuwiyaId('01HR...');          // ?User
User::findOrCreateFromHuwiya($claims);    // Called internally by the SDK
$user->huwiyaToken;                       // TokenClaims (API requests only)
```

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

## Exceptions

All exceptions extend `Huwiya\Exceptions\HuwiyaException`, which in turn extends `\RuntimeException`. You may catch either to handle all SDK errors, or target specific failure modes individually.

| Exception                        | Thrown when                                                                                        |
| -------------------------------- | -------------------------------------------------------------------------------------------------- |
| `InvalidGuardException`          | `Huwiya::redirect()` received an empty, unknown, or non-`huwiya-web` guard name.                   |
| `InvalidStateException`          | The OAuth callback session payload is missing or malformed, or the returned `state` does not match. |
| `TokenExchangeException`         | The token endpoint returned a non-2xx response or a body without `access_token`.                   |
| `InvalidJwtFormatException`      | The JWT is malformed (wrong segment count, invalid base64, invalid JSON, missing `kid` or `alg`).  |
| `InvalidTokenClaimsException`    | The JWT payload is missing a required claim (`id`, `name`, `locale`, `zoneinfo`, `theme`, `scopes`) or `id` is not a valid ULID. |
| `JwksFetchException`             | The JWKS endpoint is unreachable, returned a non-2xx response, or returned no `keys` array.        |
| `UnknownKidException`            | No JWKS key matches the JWT's `kid`, even after a cache refresh.                                   |
| `UnsupportedKeyTypeException`    | A matched JWKS key has a `kty` other than `RSA`.                                                   |
| `AuthConfigurationException`     | `auth.providers.{provider}.model` is missing, or the model does not use `InteractsWithHuwiya`.     |
| `HuwiyaUserNotFoundException`    | Auto-registration is disabled and no local user matches the incoming `sub`.                        |

Guard-level authentication failures — expired tokens, invalid signatures, mismatched issuer or audience — do **not** throw. The guards return `null`, and Laravel's built-in `auth:*` middleware responds with `401 Unauthorized` as usual.

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

## Contributing

Bug reports, feature requests, and pull requests are welcome on [GitHub](https://github.com/hitaqnia/huwiya-laravel). Please run the test suite before submitting a pull request:

```bash
composer test
```

## License

Released under the [MIT License](LICENSE).
