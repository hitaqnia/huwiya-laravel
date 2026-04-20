<?php

namespace Huwiya;

use Huwiya\Support\AuthorizationDeniedCallback;
use Illuminate\Auth\RequestGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HuwiyaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/huwiya.php', 'huwiya');

        $this->app->scoped(AuthorizationDeniedCallback::class);
        $this->app->singleton(Huwiya::class);
    }

    public function boot(): void
    {
        $this->registerRateLimiter();
        $this->defineRoutes();
        $this->configureGuard();
        $this->registerBlueprintMacros();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/huwiya.php' => config_path('huwiya.php'),
            ], 'huwiya-config');
        }
    }

    protected function registerRateLimiter(): void
    {
        RateLimiter::for('huwiya-callback', fn ($request) => Limit::perMinute(30)->by($request->ip()));
    }

    protected function registerBlueprintMacros(): void
    {
        if (Blueprint::hasMacro('huwiyaIdentifier')) {
            return;
        }

        Blueprint::macro('huwiyaIdentifier', function (string $column = 'huwiya_id') {
            /** @var Blueprint $this */
            return $this->ulid($column)->nullable()->unique();
        });
    }

    protected function defineRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $middleware = config('huwiya.callback_middleware', ['web', 'throttle:huwiya-callback']);

        Route::middleware($middleware)->group(function () {
            Route::get('/huwiya/callback', Http\Controllers\CallbackController::class)->name('huwiya.callback');
        });
    }

    protected function configureGuard(): void
    {
        Auth::resolved(function ($auth) {
            $auth->extend('huwiya-web', function ($app, $name, array $config) use ($auth) {
                $guard = new WebGuard(
                    $name,
                    $auth->createUserProvider($config['provider'] ?? null),
                    $app['session.store'],
                    rehashOnLogin: $app['config']->get('hashing.rehash_on_login', true),
                    timeboxDuration: $app['config']->get('auth.timebox_duration', 200000),
                    hashKey: $app['config']->get('app.key'),
                );

                $guard->setCookieJar($app['cookie']);
                $guard->setDispatcher($app['events']);
                $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

                if (isset($config['remember'])) {
                    $guard->setRememberDuration($config['remember']);
                }

                return $guard;
            });

            $auth->extend('huwiya-api', function ($app, $name, array $config) use ($auth) {
                return tap(
                    new RequestGuard(new ApiGuard($config['provider'] ?? null, $name), request(), $auth->createUserProvider($config['provider'] ?? null)),
                    fn ($guard) => $app->refresh('request', $guard, 'setRequest'),
                );
            });
        });
    }
}
