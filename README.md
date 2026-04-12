# Huwiya Laravel SDK

A Laravel SDK for the [Huwiya](https://huwiya.id) Identity Provider. Adds OAuth2 authorization-code login for web apps and JWT bearer authentication for APIs, plugged in as standard Laravel auth guards.

## Requirements

- PHP 8.3+
- Laravel 13.0+

## Getting Started

### 1. Install

```bash
composer require hitaqnia/huwiya
```

Service provider auto-discovery registers the package.

### 2. Configure

Add your credentials to `.env`:

```env
HUWIYA_PROJECT_ID=your-project-id
HUWIYA_CLIENT_ID=your-client-id
HUWIYA_CLIENT_SECRET=your-client-secret
```

Every other setting — IdP URL (`https://huwiya.id`), redirect URI (`{APP_URL}/huwiya/callback`), JWT algorithm, leeway, etc. — has a sensible default. See [Configuration Reference](#configuration-reference) to override.

> **Heads up:** the default `redirect_uri` is derived from `APP_URL`. Make sure `APP_URL` matches the host registered with the IdP, or set `HUWIYA_REDIRECT_URI` explicitly.

### 3. Prepare the User model

Users are identified by a `huwiya_id` column (the `sub` claim from the IdP). Add a migration:

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->ulid('huwiya_id')->unique();
    $table->string('phone')->unique();
    $table->timestamps();
});
```

The unique index on `huwiya_id` is required — the package relies on it to prevent duplicate users under concurrent first-login requests.

Add the `HasHuwiyaTokens` trait to your `User` model. **This trait is mandatory** — without it, both guards throw `Huwiya\Exceptions\AuthConfigurationException` when they attempt to resolve a user.

```php
use Huwiya\HasHuwiyaTokens;

class User extends Authenticatable
{
    use HasHuwiyaTokens;

    protected $fillable = ['name', 'huwiya_id', 'phone'];
}
```

### 4. Register guards

In `config/auth.php`, switch your web and/or API guards to the Huwiya drivers:

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

That's it. `/huwiya/redirect` starts the OAuth flow, `/huwiya/callback` handles the return, and `auth:api` accepts Bearer JWTs.

## How It Works

### Web flow (OAuth2 Authorization Code)

1. User hits `/huwiya/redirect` — the package stores a random `state` in the session and redirects to the IdP.
2. IdP bounces back to `/huwiya/callback` — the package verifies state with a timing-safe comparison, exchanges the code for a JWT at `{url}/oauth/token`, decodes the claims, and calls `User::findOrCreateFromHuwiya($claims)`.
3. The user is logged into the session. Subsequent requests authenticate via the `huwiya-web` guard, which reads the standard session key for the configured guard.

The callback redirects to `redirect()->intended('/')` — so `$request->session()->put('url.intended', …)` from your own middleware works as expected.

### API flow (JWT Bearer)

On each request, the `huwiya-api` guard:

1. Reads the `Authorization: Bearer <jwt>` header.
2. Verifies the signature against the IdP's JWKS (cached 1 hour under a key derived from the JWKS URI, auto-refetched on unknown `kid` for key rotation).
3. Validates `alg`, `exp` (with leeway), `iss`, and `aud`.
4. Calls `User::findOrCreateFromHuwiya($claims)`.
5. Attaches the `TokenClaims` to `$user->huwiyaToken`.

Stateless — no session involved.

### SPA / first-party frontend

The stateful middleware is **not registered automatically**. For cookie-based auth from a first-party SPA, register `EnsureFrontendRequestsAreStateful` yourself in `bootstrap/app.php`:

```php
use Huwiya\Http\Middleware\EnsureFrontendRequestsAreStateful;

->withMiddleware(function (Middleware $middleware) {
    $middleware->append(EnsureFrontendRequestsAreStateful::class);
})
```

Set `HUWIYA_STATEFUL_DOMAINS` to a comma-separated list of your frontend origins (localhost variants, `APP_URL`, and `FRONTEND_URL` are included by default).

## Customizing the User Mapping

The `HasHuwiyaTokens` trait has sensible defaults, but every part is overridable.

### Change the identifier column

```php
public function getHuwiyaIdentifierColumn(): string
{
    return 'sso_id';
}
```

### Map additional claim fields on create/update

```php
public function getHuwiyaCreateAttributes(TokenClaims $claims): array
{
    return [
        'name' => $claims->name,
        'phone' => $claims->phoneNumber,
        'email' => $claims->email ?? null,
        'role' => 'member',
    ];
}

public function getHuwiyaUpdateAttributes(TokenClaims $claims): array
{
    // Only refresh name — leave role untouched.
    return ['name' => $claims->name];
}
```

Return `[]` from `getHuwiyaUpdateAttributes` to skip the update entirely on re-login.

### Disable auto-registration

By default, users that don't exist locally are created on first login. To reject unknown users:

```php
public function shouldAutoRegister(): bool
{
    return false;
}
```

Unknown users cause the callback to throw `Huwiya\Exceptions\HuwiyaUserNotFoundException`. Catch it in your exception handler to redirect wherever makes sense.

### Handy helpers

```php
User::findByHuwiyaId('01HR...');          // Returns ?User
User::findOrCreateFromHuwiya($claims);    // Called by the SDK internally
$user->huwiyaToken;                       // TokenClaims on API requests
```

## Customizing the Flows

### Multiple guards

You can register as many guards as you want using the `huwiya-web` and `huwiya-api` drivers — e.g. separate `admin` and `api` guards against different providers:

```php
'guards' => [
    'web'   => ['driver' => 'huwiya-web', 'provider' => 'users'],
    'admin' => ['driver' => 'huwiya-web', 'provider' => 'admins'],
    'api'   => ['driver' => 'huwiya-api', 'provider' => 'users'],
],
```

Because the OAuth callback is a single route, it needs to know which guard to log the user into. Set `HUWIYA_WEB_GUARD=admin` (default: `web`) to point the callback at a different guard. The session key is derived from that guard name, so `huwiya-web` instances reading the session stay in sync.

### Handle authorization denial

When the user denies consent at the IdP, the callback runs a configurable handler. The default redirects to `/`. Override in a service provider:

```php
use Huwiya\Huwiya;
// or: use Huwiya\Facades\Huwiya;

public function boot(): void
{
    Huwiya::whenAuthorizationDenied(function (?string $error, ?string $description) {
        return redirect()->route('login')->with('error', $description ?? 'Authorization denied.');
    });
}
```

The callback may declare zero, one (`$error`), or two (`$error`, `$description`) parameters — the package dispatches based on the closure's arity, so the zero-arg form keeps working.

The callback is stored in a **container-scoped** singleton, so it resets per request under Octane/Swoole — no state leaks between requests.

### Configure routes

By default the package serves `/huwiya/redirect` and `/huwiya/callback`. To disable the bundled routes entirely (e.g. you mount your own controllers), set `HUWIYA_ROUTES_ENABLED=false`. To change the path prefix, set `HUWIYA_ROUTES_PREFIX=identity` (producing `/identity/redirect` and `/identity/callback`). Route names `huwiya.redirect` and `huwiya.callback` are stable regardless.

### Facade

A facade is available as an alternative to the static `Huwiya\Huwiya` class:

```php
use Huwiya\Facades\Huwiya;

Huwiya::whenAuthorizationDenied(fn () => redirect('/denied'));
Huwiya::actingAs($user, 'web');
```

### `actingAs` for tests

```php
use Huwiya\Huwiya;

Huwiya::actingAs($user, 'web');
// or
Huwiya::actingAs($user, 'api');
```

### Customize the stateful middleware

Override the cookie / CSRF middleware classes used by `EnsureFrontendRequestsAreStateful` in `config/huwiya.php`. These entries are only consulted when you manually register `EnsureFrontendRequestsAreStateful` in your middleware stack.

```php
'middleware' => [
    'encrypt_cookies' => \App\Http\Middleware\EncryptCookies::class,
    'validate_csrf_token' => \App\Http\Middleware\ValidateCsrfToken::class,
],
```

## Exceptions

The package throws typed exceptions so you can target specific failures. All extend `Huwiya\Exceptions\HuwiyaException`, which extends `\RuntimeException` — so a broad catch on either works.

| Exception | Thrown when |
| --- | --- |
| `InvalidStateException` | OAuth callback `state` missing or does not match the session. |
| `TokenExchangeException` | Token endpoint returned a non-2xx response or response body lacked `access_token`. |
| `InvalidJwtFormatException` | JWT is malformed (wrong segment count, bad base64, bad JSON, missing `kid`, wrong `alg`). |
| `InvalidTokenClaimsException` | JWT payload lacks one or more required claims (`sub`, `name`, `phone`). |
| `JwksFetchException` | JWKS endpoint unreachable, non-2xx, or returned a body without a `keys` array. |
| `UnknownKidException` | No key in the JWKS matched the JWT's `kid`, even after a cache refresh. |
| `UnsupportedKeyTypeException` | JWKS key matched `kid` but its `kty` is not `RSA`. |
| `AuthConfigurationException` | `auth.providers.{provider}.model` is missing, or the model does not use `HasHuwiyaTokens`. |
| `HuwiyaUserNotFoundException` | Auto-registration disabled and no local user matches the incoming `sub`. |

Guard-level auth failures (expired tokens, bad signatures, wrong issuer/audience) do **not** throw — guards return `null`, so Laravel's standard `auth:*` middleware responds with 401 as usual.

## Logging

Set `HUWIYA_LOG_CHANNEL=huwiya` (or any channel configured in `config/logging.php`) to receive warnings for JWKS fetch failures, signature mismatches, token-exchange errors, and authorization denials. Secrets (tokens, client secrets) are never logged; response bodies are truncated to 200 characters.

Leave the env unset and logging is a no-op.

## Configuration Reference

All settings live in `config/huwiya.php`. Publish it if you want to edit the file directly:

```bash
php artisan vendor:publish --provider="Huwiya\HuwiyaServiceProvider" --tag="huwiya-config"
```

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `url` | `HUWIYA_URL` | `https://huwiya.id` | IdP base URL. |
| `project_id` | `HUWIYA_PROJECT_ID` | *(required)* | Project that groups OAuth clients. Used as the expected `aud` claim. |
| `client_id` | `HUWIYA_CLIENT_ID` | *(required)* | OAuth client ID. |
| `client_secret` | `HUWIYA_CLIENT_SECRET` | *(required)* | OAuth client secret. |
| `redirect_uri` | `HUWIYA_REDIRECT_URI` | `{APP_URL}/huwiya/callback` | OAuth redirect. Must be registered on the IdP. |
| `routes.enabled` | `HUWIYA_ROUTES_ENABLED` | `true` | Set to `false` to disable the package-provided `/redirect` and `/callback` routes. |
| `routes.prefix` | `HUWIYA_ROUTES_PREFIX` | `huwiya` | URL prefix for the bundled routes. |
| `web_guard` | `HUWIYA_WEB_GUARD` | `web` | The auth guard the OAuth callback logs the user into. |
| `stateful` | `HUWIYA_STATEFUL_DOMAINS` | localhost + app host + `FRONTEND_URL` | Domains that get session-based auth via the stateful middleware. |
| `auth_method` | `HUWIYA_AUTH_METHOD` | `basic` | `basic` (HTTP Basic Auth) or `body` for token-endpoint client credentials. |
| `verify_signature` | `HUWIYA_VERIFY_SIGNATURE` | `true` | Disable only for local dev. **Always on in production.** |
| `algorithm` | `HUWIYA_ALGORITHM` | `RS256` | Expected JWT signing algorithm. Mismatches are rejected (prevents alg-confusion). |
| `jwks_uri` | `HUWIYA_JWKS_URI` | `{url}/{project_id}/.well-known/jwks.json` | Override if your IdP serves keys elsewhere. |
| `leeway` | `HUWIYA_TOKEN_LEEWAY` | `60` | Seconds of clock-skew tolerance for `exp`. |
| `validate_issuer` | `HUWIYA_VALIDATE_ISSUER` | `true` | Require `iss` to match `url`. |
| `validate_audience` | `HUWIYA_VALIDATE_AUDIENCE` | `true` | Require `aud` to match `project_id`. |
| `log_channel` | `HUWIYA_LOG_CHANNEL` | `null` | Log channel for diagnostics. Unset = no logging. |

## Security Notes

- Signature verification is **on by default**. It uses the IdP's public JWKS, matched by `kid`. The cache auto-busts on unknown `kid` to handle key rotation.
- `alg` is pinned to `RS256` — `alg:none` and `HS256` downgrades are rejected.
- Issuer and audience are validated by default.
- OAuth `state` is required on the callback, compared with `hash_equals` (timing-safe), and cleared from the session after use.
- JWT header/payload/signature base64 segments use **strict** base64url decoding — malformed inputs are rejected early.
- Sessions use `http_only` + `SameSite=Lax` when the stateful middleware is active.
- Session regeneration runs after a successful login to prevent session fixation.

## Testing

```bash
composer test
```

## License

MIT License. See [LICENSE](LICENSE) for details.
