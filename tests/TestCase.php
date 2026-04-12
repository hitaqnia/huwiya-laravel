<?php

namespace Huwiya\Tests;

use Huwiya\HuwiyaServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            HuwiyaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('huwiya.url', 'https://idp.example.com');
        $app['config']->set('huwiya.client_id', 'test-client-id');
        $app['config']->set('huwiya.client_secret', 'test-client-secret');
        $app['config']->set('huwiya.redirect_uri', 'https://app.example.com/callback');
        $app['config']->set('huwiya.algorithm', 'RS256');
        $app['config']->set('huwiya.leeway', 60);

        $app['config']->set('auth.providers.users.model', \Huwiya\Tests\Fixtures\User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->ulid('huwiya_id')->unique();
            $table->string('phone')->unique();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }
}
