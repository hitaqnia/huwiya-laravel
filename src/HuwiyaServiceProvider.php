<?php

namespace Huwiya;

use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HuwiyaServiceProvider extends ServiceProvider
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
            Route::get('/huwiya/redirect', Http\Controllers\RedirectController::class)->name('huwiya.redirect');
            Route::get('/huwiya/callback', Http\Controllers\CallbackController::class)->name('huwiya.callback');
        });
    }

    protected function configureGuard(): void
    {
        Auth::resolved(function ($auth) {
            $auth->extend('huwiya-web', function ($app, $name, array $config) use ($auth) {
                $provider = $auth->createUserProvider($config['provider'] ?? null);

                return tap(
                    new RequestGuard(new WebGuard($provider), request(), $provider),
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

    protected function configureMiddleware(): void
    {
        $kernel = app()->make(Kernel::class);

        $kernel->prependToMiddlewarePriority(Http\Middleware\EnsureFrontendRequestsAreStateful::class);
    }
}
