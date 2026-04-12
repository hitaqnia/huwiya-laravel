<?php

namespace Huwiya\Http\Controllers;

use Huwiya\Exceptions\AuthConfigurationException;
use Huwiya\Exceptions\InvalidStateException;
use Huwiya\Exceptions\TokenExchangeException;
use Huwiya\HasHuwiyaTokens;
use Huwiya\Huwiya;
use Huwiya\TokenClaims;
use Illuminate\Http\Request;
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

        $state = $request->session()->pull('state');

        if (! is_string($state) || $state === '' || ! hash_equals($state, (string) $request->input('state'))) {
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

        return $this->authenticateUser($claims, $request);
    }

    /**
     * Authenticate the user from the token claims and log them in via session.
     */
    protected function authenticateUser(TokenClaims $claims, Request $request): Response
    {
        $guard = config('huwiya.web_guard', 'web');
        $provider = config("auth.guards.{$guard}.provider", 'users');
        $model = config("auth.providers.{$provider}.model");

        if (! $model) {
            throw new AuthConfigurationException('Unable to determine user model from auth configuration.');
        }

        if (! in_array(HasHuwiyaTokens::class, class_uses_recursive($model), true)) {
            throw new AuthConfigurationException("The model [{$model}] must use the HasHuwiyaTokens trait.");
        }

        $user = $model::findOrCreateFromHuwiya($claims);

        $request->session()->put(
            Huwiya::sessionKeyForGuard($guard),
            $user->getAuthIdentifier(),
        );

        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
