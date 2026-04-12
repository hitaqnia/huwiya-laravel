<?php

namespace Huwiya\Http\Controllers;

use Huwiya\HasHuwiyaTokens;
use Huwiya\Huwiya;
use Huwiya\TokenClaims;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class CallbackController
{
    public function __invoke(Request $request): Response
    {
        $state = $request->session()->pull('state');

        throw_unless(
            strlen($state) > 0 && $state === $request->state,
            \InvalidArgumentException::class,
            'Invalid state value.'
        );

        if ($request->has('error')) {
            return Huwiya::denied();
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

        throw_unless($response->successful(), RuntimeException::class, 'Failed to retrieve access token.');

        $claims = TokenClaims::fromJwt($response->json('access_token'));

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

        throw_unless($model, RuntimeException::class, 'Unable to determine user model from auth configuration.');
        throw_unless(
            in_array(HasHuwiyaTokens::class, class_uses_recursive($model)),
            RuntimeException::class,
            "The model [{$model}] must use the HasHuwiyaTokens trait."
        );

        $user = $model::findOrCreateFromHuwiya($claims);

        $request->session()->put(
            'login_web_'.sha1('Illuminate\Auth\SessionGuard'),
            $user->getAuthIdentifier(),
        );

        $request->session()->regenerate();

        $intended = $request->session()->pull('url.intended', '/');

        return redirect($intended);
    }
}
