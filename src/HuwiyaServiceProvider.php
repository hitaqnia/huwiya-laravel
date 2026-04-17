<?php

namespace Huwiya;

use Huwiya\Support\AuthorizationDeniedCallback;
use Huwiya\Support\HuwiyaManager;
use Illuminate\Auth\RequestGuard;
use Illuminate\Database\Schema\Blueprint;
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
        $this->registerBlueprintMacros();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/huwiya.php' => config_path('huwiya.php'),
            ], 'huwiya-config');
        }
    }

    protected function registerBlueprintMacros(): void
    {
        if (Blueprint::hasMacro('huwiyaIdentifier')) {
            return;
        }

        Blueprint::macro('huwiyaIdentifier', function (string $column = 'huwiya_id') {
            /** @var Blueprint $this */
            return $this->ulid($column)->unique();
        });
    }

    protected function defineRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(function () {
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
