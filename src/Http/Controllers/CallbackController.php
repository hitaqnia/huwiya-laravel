<?php

namespace Huwiya\Http\Controllers;

use Huwiya\Exceptions\HuwiyaConflictException;
use Huwiya\Exceptions\HuwiyaUserNotFoundException;
use Huwiya\Exceptions\InvalidStateException;
use Huwiya\Exceptions\TokenExchangeException;
use Huwiya\Huwiya;
use Huwiya\Support\TokenRejection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class CallbackController
{
    public function __invoke(Request $request): Response
    {
        try {
            return $this->handle($request);
        } catch (InvalidStateException $e) {
            Huwiya::log()?->warning('Huwiya: callback rejected — '.$e->getMessage());
            abort(400, 'Invalid OAuth callback.');
        } catch (TokenExchangeException $e) {
            Huwiya::log()?->warning('Huwiya: token exchange failed — '.$e->getMessage());
            abort(502, 'Identity provider error.');
        } catch (HuwiyaUserNotFoundException $e) {
            Huwiya::log()?->info('Huwiya: user not found and auto-registration disabled.');
            abort(403, 'Access denied.');
        } catch (HuwiyaConflictException $e) {
            Huwiya::log()?->warning('Huwiya: conflict resolving user.', [
                'column' => $e->conflictingColumn,
            ]);
            abort(409, 'Account conflict. Please contact support.');
        }
    }

    /**
     * @throws InvalidStateException
     * @throws TokenExchangeException
     * @throws HuwiyaUserNotFoundException
     */
    protected function handle(Request $request): Response
    {
        if ($request->has('error')) {
            $error = (string) $request->input('error');
            $description = $request->input('error_description');

            Huwiya::log()?->warning('Huwiya: authorization denied at IdP.', [
                'error' => $error,
                'error_description' => $description,
            ]);

            return Huwiya::denied($error, $description);
        }

        $bound = $request->session()->pull('huwiya.oauth');

        if (! is_array($bound)
            || ! isset($bound['state'], $bound['guard'])
            || ! is_string($bound['state']) || $bound['state'] === ''
            || ! is_string($bound['guard']) || $bound['guard'] === ''
        ) {
            throw new InvalidStateException('Missing or malformed OAuth session payload.');
        }

        $state = $bound['state'];
        $guard = $bound['guard'];
        $intended = (isset($bound['intended']) && is_string($bound['intended']) && $bound['intended'] !== '')
            ? $bound['intended']
            : null;

        if (! hash_equals($state, (string) $request->input('state'))) {
            throw new InvalidStateException('Invalid state value.');
        }

        // Defense in depth: validate the bound guard before spending a network
        // round-trip on the IdP. If an operator changed the guard between the
        // redirect and the callback, refuse to proceed.
        Huwiya::assertGuardIsHuwiyaWeb($guard);

        $code = $request->input('code');

        if (! is_string($code) || $code === '') {
            throw new InvalidStateException('Missing or invalid authorization code.');
        }

        $accessToken = $this->exchangeCodeForToken($code);

        $tokenResult = Huwiya::decodeAndVerifyToken($accessToken);

        if ($tokenResult->isFailure()) {
            $error = $tokenResult->getError();

            Huwiya::log()?->warning('Huwiya: callback token rejected.', [
                'code' => $error?->getCode(),
                'reason' => $error?->getMessage(),
            ]);

            // Missing/malformed claims from the IdP are a bad-gateway situation;
            // signature/expiry/issuer/audience failures are authentication failures.
            $rejectionCode = $error?->getCode();

            if (in_array($rejectionCode, [TokenRejection::JWKS_UNAVAILABLE, TokenRejection::MALFORMED], true)) {
                throw new TokenExchangeException('Identity provider returned an unusable token.');
            }

            abort(401, 'Authentication failed.');
        }

        $claims = $tokenResult->getData();

        return $this->authenticateUser($claims, $request, $guard, $intended);
    }

    /**
     * @throws TokenExchangeException
     */
    protected function exchangeCodeForToken(string $code): string
    {
        $http = Http::asForm()->timeout((int) config('huwiya.http_timeout', 10));

        $tokenParams = [
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('huwiya.redirect_uri'),
            'code' => $code,
        ];

        if (config('huwiya.auth_method', 'basic') === 'basic') {
            $http = $http->withBasicAuth(config('huwiya.client_id'), config('huwiya.client_secret'));
        } else {
            $tokenParams['client_id'] = config('huwiya.client_id');
            $tokenParams['client_secret'] = config('huwiya.client_secret');
        }

        try {
            $response = $http->post(config('huwiya.url').'/oauth/token', $tokenParams);
        } catch (\Throwable $e) {
            // Redact exception messages — may contain URL/headers.
            Huwiya::log()?->warning('Huwiya: token endpoint request failed.', [
                'exception' => $e::class,
            ]);

            throw new TokenExchangeException('Token endpoint request failed.');
        }

        if (! $response->successful()) {
            Huwiya::log()?->warning('Huwiya: token exchange returned non-2xx status.', [
                'status' => $response->status(),
            ]);

            throw new TokenExchangeException('Failed to retrieve access token.');
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            Huwiya::log()?->warning('Huwiya: token exchange response missing access_token.');

            throw new TokenExchangeException('Token endpoint response did not include an access_token.');
        }

        return $accessToken;
    }

    /**
     * Authenticate the user from the token claims and log them in via session.
     *
     * @throws HuwiyaUserNotFoundException
     */
    protected function authenticateUser(\Huwiya\TokenClaims $claims, Request $request, string $guard, ?string $intended): Response
    {
        $provider = config("auth.guards.{$guard}.provider");
        $model = config("auth.providers.{$provider}.model");

        $user = $model::findOrCreateFromHuwiya($claims, $guard);

        Auth::guard($guard)->login($user);

        $request->session()->regenerate();

        $fallback = $intended ?? config('huwiya.home', '/');

        return redirect()->intended($fallback);
    }
}
