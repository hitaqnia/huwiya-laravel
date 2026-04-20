<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

return [

    /*
    |--------------------------------------------------------------------------
    | IdP URL
    |--------------------------------------------------------------------------
    |
    | The base URL of the Identity Provider server.
    |
    */

    'url' => env('HUWIYA_URL', 'https://huwiya.id'),

    /*
    |--------------------------------------------------------------------------
    | Project ID
    |--------------------------------------------------------------------------
    |
    | The project ID that groups related OAuth clients. Tokens issued by the
    | IdP carry the project ID as the audience claim, allowing multiple
    | clients within the same project to accept each other's tokens.
    |
    */

    'project_id' => env('HUWIYA_PROJECT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Client ID
    |--------------------------------------------------------------------------
    |
    | The client ID issued by the Identity Provider.
    |
    */

    'client_id' => env('HUWIYA_CLIENT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Client Secret
    |--------------------------------------------------------------------------
    |
    | The client secret issued by the Identity Provider.
    |
    */

    'client_secret' => env('HUWIYA_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Redirect URI
    |--------------------------------------------------------------------------
    |
    | The URI the Identity Provider will redirect to after authorization.
    |
    */

    'redirect_uri' => env('HUWIYA_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/huwiya/callback'),

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from these domains will receive stateful authentication
    | (session/cookie-based). Typically this is your first-party frontend
    | domain. Requests from other origins will be treated as stateless
    | API requests and authenticated via the JWT bearer token.
    |
    */

    'stateful' => array_values(array_filter(array_map('trim', explode(',', env(
        'HUWIYA_STATEFUL_DOMAINS',
        implode(',', array_filter([
            'localhost',
            'localhost:3000',
            '127.0.0.1',
            '127.0.0.1:8000',
            '::1',
            parse_url(env('APP_URL', ''), PHP_URL_HOST),
            parse_url(env('FRONTEND_URL', ''), PHP_URL_HOST),
        ])),
    ))))),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware classes used during stateful request handling. These are
    | only consulted when you manually register
    | `Huwiya\Http\Middleware\EnsureFrontendRequestsAreStateful` in your
    | application's middleware stack — see the README "Frontend SPA"
    | section. Override these entries if your app uses custom cookie or
    | CSRF middleware.
    |
    */

    'middleware' => [
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verify Signature
    |--------------------------------------------------------------------------
    |
    | When disabled, JWT signature verification is skipped and only the token
    | payload is decoded. This is useful during local development or testing
    | when no public key is available. Should always be enabled in production.
    |
    */

    'verify_signature' => env('HUWIYA_VERIFY_SIGNATURE', true),

    /*
    |--------------------------------------------------------------------------
    | JWKS URI
    |--------------------------------------------------------------------------
    |
    | The URI to fetch the IdP's JSON Web Key Set. Public keys are matched
    | by the kid header in the JWT. Defaults to {url}/{project_id}/.well-known/jwks.json.
    |
    */

    'jwks_uri' => env('HUWIYA_JWKS_URI'),

    /*
    |--------------------------------------------------------------------------
    | Token Leeway
    |--------------------------------------------------------------------------
    |
    | Number of seconds of leeway to allow when verifying token expiration.
    | This accounts for clock skew between the IdP and your server.
    |
    */

    'leeway' => env('HUWIYA_TOKEN_LEEWAY', 60),

    /*
    |--------------------------------------------------------------------------
    | JWT Algorithm
    |--------------------------------------------------------------------------
    |
    | The expected signing algorithm for JWT tokens from the IdP. Tokens using
    | a different algorithm will be rejected. This prevents algorithm confusion
    | attacks (e.g. an attacker switching RS256 to HS256).
    |
    */

    'algorithm' => env('HUWIYA_ALGORITHM', 'RS256'),

    /*
    |--------------------------------------------------------------------------
    | Validate Issuer
    |--------------------------------------------------------------------------
    |
    | When enabled, tokens must contain an 'iss' claim matching the configured
    | IdP URL. This prevents accepting tokens issued by a different authority.
    |
    */

    'validate_issuer' => env('HUWIYA_VALIDATE_ISSUER', true),

    /*
    |--------------------------------------------------------------------------
    | Validate Audience
    |--------------------------------------------------------------------------
    |
    | When enabled, tokens must contain an 'aud' claim matching the configured
    | project ID. This prevents token replay attacks where a token issued for
    | a different project is used against this application.
    |
    */

    'validate_audience' => env('HUWIYA_VALIDATE_AUDIENCE', true),

    /*
    |--------------------------------------------------------------------------
    | Client Authentication Method
    |--------------------------------------------------------------------------
    |
    | How the client authenticates with the IdP token endpoint.
    | Supported: "basic" (HTTP Basic Auth, recommended), "body" (POST body).
    |
    */

    'auth_method' => env('HUWIYA_AUTH_METHOD', 'basic'),

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | The log channel the package should write warnings to when authentication
    | or JWKS operations fail. When null (the default) logging is a no-op.
    | Set this to a channel configured in config/logging.php — for example
    | "stack", "single", or a dedicated "huwiya" channel — to receive
    | diagnostics for failed token exchanges, JWKS fetches, signature
    | mismatches, and authorization denials.
    |
    */

    'log_channel' => env('HUWIYA_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for outbound HTTP calls from the SDK (token exchange,
    | JWKS fetch). A hung IdP shouldn't be able to tie up a worker indefinitely.
    |
    */

    'http_timeout' => (int) env('HUWIYA_HTTP_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Post-Login Home URL
    |--------------------------------------------------------------------------
    |
    | The fallback URL the callback redirects to after a successful login when
    | no `intended` URL was stored. Individual redirects may override per-request
    | by passing an intended URL to `Huwiya::redirect()`.
    |
    */

    'home' => env('HUWIYA_HOME', '/'),

    /*
    |--------------------------------------------------------------------------
    | Callback Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware stack for the `/huwiya/callback` route. Defaults include a
    | rate limiter named `huwiya-callback` registered by the service provider
    | (30 requests/minute per IP). Override if you need different limits or
    | additional middleware.
    |
    */

    'callback_middleware' => ['web', 'throttle:huwiya-callback'],

];
