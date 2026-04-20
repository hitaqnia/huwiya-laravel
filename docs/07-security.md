# Security

The SDK is designed to be secure by default.

- **Signature verification** is on by default. Keys are fetched from the IdP's JWKS endpoint and matched by `kid`. On unknown `kid`, the cache is invalidated under a short lock so concurrent requests coalesce into a single refetch rather than a thundering herd.
- **Algorithm pinning.** The expected JWT algorithm is pinned to `RS256`. Downgrade attacks using `alg:none` or `HS256` are rejected.
- **Claim validation.** Issuer (`iss`) and audience (`aud`) claims are validated by default.
- **OAuth state** is required on the callback, compared with `hash_equals()` (timing-safe), and consumed single-use via `pull`.
- **Guard binding.** The target guard is chosen by your server-side code when you call `Huwiya::redirect($guard)` and travels with the state in one atomic session entry. The callback re-validates the bound guard before calling `login()` — the guard cannot be influenced by user input, and a guard removed or altered between the redirect and the callback fails the request rather than logging into an unintended one.
- **HTTP timeouts.** Outbound calls to the IdP (token exchange, JWKS fetch) are bounded by `config('huwiya.http_timeout')`. A hung IdP cannot tie up a worker indefinitely.
- **Rate limiting.** The `/huwiya/callback` route is limited to 30 requests per minute per IP by default.
- **Session fixation** is prevented by regenerating the session ID after a successful login.
- **Strict base64url decoding** is applied to every JWT segment — malformed inputs are rejected early.
- **Secrets are not logged.** Access tokens, client secrets, and response bodies are never written to the log channel. Only metadata (HTTP status, exception class, rejection code) is recorded.

## Reporting vulnerabilities

If you discover a security vulnerability, email **info@hitaqnia.com** rather than opening a public issue.
