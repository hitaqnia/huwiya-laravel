<?php

namespace Huwiya\Http\Controllers;

use Huwiya\Exceptions\InvalidStateException;
use Huwiya\Exceptions\TokenExchangeException;
use Huwiya\Huwiya;
use Huwiya\TokenClaims;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class CallbackController
{
    public function __invoke(Request $request): Response
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

        if (! hash_equals($state, (string) $request->input('state'))) {
            throw new InvalidStateException('Invalid state value.');
        }

        $http = Http::asForm();

        $tokenParams = [
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('huwiya.redirect_uri'),
            'code' => $request->code,
        ];

        if (config('huwiya.auth_method', 'basic') === 'basic') {
            $http = $http->withBasicAuth(config('huwiya.client_id'), config('huwiya.client_secret'));
        } else {
            $tokenParams['client_id'] = config('huwiya.client_id');
            $tokenParams['client_secret'] = config('huwiya.client_secret');
        }

        $response = $http->post(config('huwiya.url').'/oauth/token', $tokenParams);

        if (! $response->successful()) {
            Huwiya::log()?->warning('Huwiya: token exchange failed.', [
                'status' => $response->status(),
                'body' => \Illuminate\Support\Str::limit((string) $response->body(), 200),
            ]);

            throw new TokenExchangeException('Failed to retrieve access token.');
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            Huwiya::log()?->warning('Huwiya: token exchange response missing access_token.');

            throw new TokenExchangeException('Token endpoint response did not include an access_token.');
        }

        $claims = TokenClaims::fromJwt($accessToken);

        return $this->authenticateUser($claims, $request, $guard);
    }

    /**
     * Authenticate the user from the token claims and log them in via session.
     */
    protected function authenticateUser(TokenClaims $claims, Request $request, string $guard): Response
    {
        Huwiya::assertGuardIsHuwiyaWeb($guard);

        $provider = config("auth.guards.{$guard}.provider");
        $model = config("auth.providers.{$provider}.model");

        $user = $model::findOrCreateFromHuwiya($claims, $guard);

        Auth::guard($guard)->login($user);

        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
