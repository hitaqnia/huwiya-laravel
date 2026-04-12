<?php

namespace Huwiya;

use Huwiya\Support\AuthorizationDeniedCallback;
use Huwiya\Support\HuwiyaManager;
use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HuwiyaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/huwiya.php', 'huwiya');

        $this->app->scoped(AuthorizationDeniedCallback::class);
        $this->app->singleton(HuwiyaManager::class);
    }

    public function boot(): void
    {
        $this->defineRoutes();
        $this->configureGuard();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/huwiya.php' => config_path('huwiya.php'),
            ], 'huwiya-config');
        }
    }

    protected function defineRoutes(): void
    {
        if (! config('huwiya.routes.enabled', true)) {
            return;
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        $prefix = trim((string) config('huwiya.routes.prefix', 'huwiya'), '/');
        $prefix = $prefix === '' ? 'huwiya' : $prefix;

        Route::middleware('web')->prefix($prefix)->group(function () {
            Route::get('/redirect', Http\Controllers\RedirectController::class)->name('huwiya.redirect');
            Route::get('/callback', Http\Controllers\CallbackController::class)->name('huwiya.callback');
        });
    }

    protected function configureGuard(): void
    {
        Auth::resolved(function ($auth) {
            $auth->extend('huwiya-web', function ($app, $name, array $config) use ($auth) {
                $provider = $auth->createUserProvider($config['provider'] ?? null);

                return tap(
                    new RequestGuard(new WebGuard($provider, $name), request(), $provider),
                    fn ($guard) => $app->refresh('request', $guard, 'setRequest'),
                );
            });

            $auth->extend('huwiya-api', function ($app, $name, array $config) use ($auth) {
                return tap(
                    new RequestGuard(new ApiGuard($config['provider'] ?? null), request(), $auth->createUserProvider($config['provider'] ?? null)),
                    fn ($guard) => $app->refresh('request', $guard, 'setRequest'),
                );
            });
        });
    }
}
