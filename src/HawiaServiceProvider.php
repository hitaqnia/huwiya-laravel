<?php

namespace Hawia;

use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HawiaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! app()->configurationIsCached()) {
            $this->mergeConfigFrom(__DIR__.'/../config/huwiya.php', 'huwiya');
        }
    }

    public function boot(): void
    {
        $this->defineRoutes();
        $this->configureGuard();
        $this->configureMiddleware();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/huwiya.php' => config_path('huwiya.php'),
            ], 'huwiya-config');
        }
    }

    protected function defineRoutes(): void
    {
        if (app()->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(function () {
            Route::get('/hawia/redirect', Http\Controllers\RedirectController::class)->name('hawia.redirect');
            Route::get('/hawia/callback', Http\Controllers\CallbackController::class)->name('hawia.callback');
        });
    }

    protected function configureGuard(): void
    {
        Auth::resolved(function ($auth) {
            $auth->extend('hawia-web', function ($app, $name, array $config) use ($auth) {
                $provider = $auth->createUserProvider($config['provider'] ?? null);

                return tap(
                    new RequestGuard(new WebGuard($provider), request(), $provider),
                    fn ($guard) => $app->refresh('request', $guard, 'setRequest'),
                );
            });

            $auth->extend('hawia-api', function ($app, $name, array $config) use ($auth) {
                return tap(
                    new RequestGuard(new ApiGuard($config['provider'] ?? null), request(), $auth->createUserProvider($config['provider'] ?? null)),
                    fn ($guard) => $app->refresh('request', $guard, 'setRequest'),
                );
            });
        });
    }

    protected function configureMiddleware(): void
    {
        $kernel = app()->make(Kernel::class);

        $kernel->prependToMiddlewarePriority(Http\Middleware\EnsureFrontendRequestsAreStateful::class);
    }
}
