# Errors

The SDK uses two distinct error mechanisms, matched to the kind of failure they represent.

- **Result pattern** — for expected domain outcomes (token rejection). Rejecting an HTTP-borne third-party JWT for being malformed, expired, or signed with the wrong key is routine, not exceptional.
- **Exceptions** — for programmer and configuration errors. The SDK fails loudly so you notice.

## Token rejection — the Result pattern

`Huwiya::decodeAndVerifyToken()` returns `Huwiya\Support\Result<TokenClaims>`:

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
        $code === TokenRejection::EXPIRED => /* 401, prompt refresh */,
        in_array($code, [
            TokenRejection::BAD_SIGNATURE,
            TokenRejection::BAD_ISSUER,
            TokenRejection::BAD_AUDIENCE,
        ], true) => /* 401, reject */,
        in_array($code, [
            TokenRejection::MALFORMED,
            TokenRejection::MISSING_CLAIMS,
        ], true) => /* 400, bad input */,
        in_array($code, [
            TokenRejection::JWKS_UNAVAILABLE,
            TokenRejection::UNKNOWN_KID,
            TokenRejection::UNSUPPORTED_KEY_TYPE,
        ], true) => /* 502, IdP issue */,
    };
}
```

Callers that don't care about the specific reason may use `Huwiya::tryDecodeAndVerifyToken()`, which returns `TokenClaims` on success and `null` on any failure. That's what the `huwiya-api` guard uses internally, so Laravel's `auth:api` middleware responds with `401 Unauthorized` as usual.

### Rejection codes

All codes live on the `Huwiya\Support\TokenRejection` class.

| Code                      | Meaning                                                           |
| ------------------------- | ----------------------------------------------------------------- |
| `MALFORMED`               | Segment count, base64, or JSON decoding failed.                   |
| `BAD_SIGNATURE`           | Signature verification failed.                                    |
| `EXPIRED`                 | `exp` claim is in the past (after `leeway`).                      |
| `BAD_ISSUER`              | `iss` claim does not match the configured IdP URL.                |
| `BAD_AUDIENCE`            | `aud` claim does not match the configured project ID.             |
| `MISSING_CLAIMS`          | Required identity claims (`id`, `name`, `phone`) are absent.      |
| `JWKS_UNAVAILABLE`        | JWKS endpoint unreachable or returned a bad response.             |
| `UNKNOWN_KID`             | No JWKS key matched the JWT's `kid`, even after a refetch.        |
| `UNSUPPORTED_KEY_TYPE`    | A matched JWKS key has a `kty` other than `RSA`.                  |

## Exceptions

All SDK exceptions extend `Huwiya\Exceptions\HuwiyaException` (itself a `RuntimeException`).

| Exception                    | Thrown when                                                                                                                              |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `InvalidGuardException`      | `Huwiya::redirect()` received an empty, unknown, or non-`huwiya-web` guard name.                                                         |
| `AuthConfigurationException` | `auth.providers.{provider}.model` is missing, or the model does not use `InteractsWithHuwiya`.                                           |
| `InvalidStateException`      | The OAuth session payload is missing/malformed, `state` does not match, or `code` is absent.                                             |
| `TokenExchangeException`     | The token endpoint timed out, returned non-2xx, or produced an unusable body.                                                            |
| `HuwiyaUserNotFoundException`| Auto-registration is disabled and the authenticated subject has no local row.                                                            |
| `HuwiyaConflictException`    | Writing claim data hit a unique-constraint violation (phone/email recycle) and the model's `resolveHuwiyaConflict` policy did not clear it. |

## Callback HTTP status codes

The callback route translates every failure class into a generic HTTP status — no stack traces, no internal detail leaks to the browser.

| Status | Meaning                                                                                |
| ------ | -------------------------------------------------------------------------------------- |
| `400`  | Invalid or missing `state`, missing `code`, malformed session payload.                 |
| `401`  | Token failed signature, issuer, audience, or expiry validation.                        |
| `403`  | Auto-registration is disabled and the user has no matching local row.                  |
| `409`  | Claim data conflicts with an existing user and the conflict policy did not resolve it. |
| `429`  | Rate limiter exceeded (default: 30 callbacks per minute per IP).                       |
| `502`  | Token endpoint unreachable, non-2xx, or returned an unusable token.                    |

Override the rate limit via `config('huwiya.callback_middleware')` — see [configuration](02-configuration.md).
