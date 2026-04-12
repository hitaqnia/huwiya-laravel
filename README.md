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

Add the `HasHuwiyaTokens` trait to your `User` model:

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
2. IdP bounces back to `/huwiya/callback` — the package verifies state, exchanges the code for a JWT at `{url}/oauth/token`, decodes the claims, and calls `User::findOrCreateFromHuwiya($claims)`.
3. The user is logged into the session. Subsequent requests authenticate via the `huwiya-web` guard, which reads the standard session key.

The callback redirects to `session('url.intended')` if set, otherwise `/`.

### API flow (JWT Bearer)

On each request, the `huwiya-api` guard:

1. Reads the `Authorization: Bearer <jwt>` header.
2. Verifies the signature against the IdP's JWKS (cached 1 hour at `huwiya:jwks`, auto-refetched on unknown `kid` for key rotation).
3. Validates `alg`, `exp` (with leeway), `iss`, and `aud`.
4. Calls `User::findOrCreateFromHuwiya($claims)`.
5. Attaches the `TokenClaims` to `$user->huwiyaToken`.

Stateless — no session involved.

### SPA / first-party frontend

For cookie-based auth from a first-party SPA, add the stateful middleware to the web group:

```php
use Huwiya\Http\Middleware\EnsureFrontendRequestsAreStateful;

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(EnsureFrontendRequestsAreStateful::class);
})
```

Set `HUWIYA_STATEFUL_DOMAINS` to a comma-separated list of your frontend origins (localhost variants are included by default).

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

Unknown users cause the callback to throw a `RuntimeException`. Catch it in your exception handler to redirect wherever makes sense.

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

Because the OAuth callback is a single route, it needs to know which guard to use when creating/finding the user. Set `HUWIYA_WEB_GUARD=admin` (default: `web`) to point the callback at a different guard.

### Handle authorization denial

When the user denies consent at the IdP, the callback runs a configurable handler. The default redirects to `/`. Override in a service provider:

```php
use Huwiya\Huwiya;

public function boot(): void
{
    Huwiya::whenAuthorizationDenied(function () {
        return redirect()->route('login')->with('error', 'Authorization denied.');
    });
}
```

### `actingAs` for tests

```php
use Huwiya\Huwiya;

Huwiya::actingAs($user, 'web');
// or
Huwiya::actingAs($user, 'api');
```

### Customize the stateful middleware

Override the cookie / CSRF middleware classes used by `EnsureFrontendRequestsAreStateful` in `config/huwiya.php`:

```php
'middleware' => [
    'encrypt_cookies' => \App\Http\Middleware\EncryptCookies::class,
    'validate_csrf_token' => \App\Http\Middleware\ValidateCsrfToken::class,
],
```

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
| `web_guard` | `HUWIYA_WEB_GUARD` | `web` | The auth guard the OAuth callback should log the user into. |
| `stateful` | `HUWIYA_STATEFUL_DOMAINS` | localhost + app host + `FRONTEND_URL` | Domains that get session-based auth via the stateful middleware. |
| `auth_method` | `HUWIYA_AUTH_METHOD` | `basic` | `basic` (HTTP Basic Auth) or `body` for token-endpoint client credentials. |
| `verify_signature` | `HUWIYA_VERIFY_SIGNATURE` | `true` | Disable only for local dev. **Always on in production.** |
| `algorithm` | `HUWIYA_ALGORITHM` | `RS256` | Expected JWT signing algorithm. Mismatches are rejected (prevents alg-confusion). |
| `jwks_uri` | `HUWIYA_JWKS_URI` | `{url}/{project_id}/.well-known/jwks.json` | Override if your IdP serves keys elsewhere. |
| `leeway` | `HUWIYA_TOKEN_LEEWAY` | `60` | Seconds of clock-skew tolerance for `exp`. |
| `validate_issuer` | `HUWIYA_VALIDATE_ISSUER` | `true` | Require `iss` to match `url`. |
| `validate_audience` | `HUWIYA_VALIDATE_AUDIENCE` | `true` | Require `aud` to match `project_id`. |

## Security Notes

- Signature verification is **on by default**. It uses the IdP's public JWKS, matched by `kid`. The cache auto-busts on unknown `kid` to handle key rotation.
- `alg` is pinned to `RS256` — `alg:none` and `HS256` downgrades are rejected.
- Issuer and audience are validated by default.
- OAuth `state` is required on the callback and cleared from the session after use.
- Sessions use `http_only` + `SameSite=Lax` when the stateful middleware is active.

## Testing

```bash
composer test
```

## License

MIT License. See [LICENSE](LICENSE) for details.
