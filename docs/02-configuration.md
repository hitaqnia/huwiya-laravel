# Configuration

All settings live in `config/huwiya.php`. Most are driven by environment variables.

## Environment variables

```dotenv
HUWIYA_PROJECT_ID=your-project-id
HUWIYA_CLIENT_ID=your-client-id
HUWIYA_CLIENT_SECRET=your-client-secret

# Optional overrides
HUWIYA_URL=https://huwiya.id
HUWIYA_REDIRECT_URI=
HUWIYA_AUTH_METHOD=basic
HUWIYA_VERIFY_SIGNATURE=true
HUWIYA_ALGORITHM=RS256
HUWIYA_TOKEN_LEEWAY=60
HUWIYA_VALIDATE_ISSUER=true
HUWIYA_VALIDATE_AUDIENCE=true
HUWIYA_HTTP_TIMEOUT=10
HUWIYA_HOME=/
HUWIYA_LOG_CHANNEL=
HUWIYA_STATEFUL_DOMAINS=
HUWIYA_JWKS_URI=
```

## Full reference

| Key                   | Env                         | Default                                       | Description                                                                       |
| --------------------- | --------------------------- | --------------------------------------------- | --------------------------------------------------------------------------------- |
| `url`                 | `HUWIYA_URL`                | `https://huwiya.id`                           | IdP base URL.                                                                     |
| `project_id`          | `HUWIYA_PROJECT_ID`         | *(required)*                                  | Project grouping OAuth clients. Used as the expected `aud` claim.                 |
| `client_id`           | `HUWIYA_CLIENT_ID`          | *(required)*                                  | OAuth client ID.                                                                  |
| `client_secret`       | `HUWIYA_CLIENT_SECRET`      | *(required)*                                  | OAuth client secret.                                                              |
| `redirect_uri`        | `HUWIYA_REDIRECT_URI`       | `{APP_URL}/huwiya/callback`                   | OAuth redirect URI. Must be registered with the IdP.                              |
| `stateful`            | `HUWIYA_STATEFUL_DOMAINS`   | localhost + `APP_URL` host + `FRONTEND_URL`   | Domains that receive session-based authentication via the stateful middleware.    |
| `auth_method`         | `HUWIYA_AUTH_METHOD`        | `basic`                                       | Client authentication method at the token endpoint: `basic` or `body`.            |
| `verify_signature`    | `HUWIYA_VERIFY_SIGNATURE`   | `true`                                        | Disable only for local development. **Always on in production.**                  |
| `algorithm`           | `HUWIYA_ALGORITHM`          | `RS256`                                       | Expected JWT signing algorithm. Mismatches are rejected.                          |
| `jwks_uri`            | `HUWIYA_JWKS_URI`           | `{url}/{project_id}/.well-known/jwks.json`    | Override if the IdP serves keys from a different location.                        |
| `leeway`              | `HUWIYA_TOKEN_LEEWAY`       | `60`                                          | Clock-skew tolerance for the `exp` claim, in seconds.                             |
| `validate_issuer`     | `HUWIYA_VALIDATE_ISSUER`    | `true`                                        | Require the `iss` claim to match `url`.                                           |
| `validate_audience`   | `HUWIYA_VALIDATE_AUDIENCE`  | `true`                                        | Require the `aud` claim to match `project_id`.                                    |
| `http_timeout`        | `HUWIYA_HTTP_TIMEOUT`       | `10`                                          | Timeout in seconds for outbound HTTP calls (token exchange, JWKS fetch).          |
| `home`                | `HUWIYA_HOME`               | `/`                                           | Fallback post-login URL when no intended URL was passed to `Huwiya::redirect()`.  |
| `callback_middleware` | *(not env-driven)*          | `['web', 'throttle:huwiya-callback']`         | Middleware stack for `/huwiya/callback`. Default rate limit is 30/min/IP.         |
| `log_channel`         | `HUWIYA_LOG_CHANNEL`        | `null`                                        | Log channel for diagnostics. Leave unset to disable logging.                      |
| `middleware`          | *(not env-driven)*          | Laravel defaults                              | Cookie/CSRF middleware for the SPA stateful pipeline.                             |

## Registering multiple guards

You may register any number of guards using the `huwiya-web` and `huwiya-api` drivers:

```php
'guards' => [
    'web'   => ['driver' => 'huwiya-web', 'provider' => 'users'],
    'admin' => ['driver' => 'huwiya-web', 'provider' => 'admins'],
    'api'   => ['driver' => 'huwiya-api', 'provider' => 'users'],
],
```

The callback route is shared by every `huwiya-web` guard. The guard the user lands in is chosen when you call `Huwiya::redirect($guard)` and travels with the OAuth `state` in one atomic session entry. Expose a separate login route per guard.

## SPA / first-party frontend

The stateful middleware required for cookie-based SPA authentication is **not** registered automatically. Opt in by appending it to your global middleware stack in `bootstrap/app.php`:

```php
use Huwiya\Http\Middleware\EnsureFrontendRequestsAreStateful;

->withMiddleware(function (Middleware $middleware) {
    $middleware->append(EnsureFrontendRequestsAreStateful::class);
})
```

Configure `HUWIYA_STATEFUL_DOMAINS` with a comma-separated list of trusted frontend origins.

Session cookie hardening is your responsibility. Set these in `config/session.php`:

```php
'http_only' => true,
'same_site' => 'lax',
```
