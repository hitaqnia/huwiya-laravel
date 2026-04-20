# Getting started

## Requirements

| Dependency | Version |
| ---------- | ------- |
| PHP        | `^8.3`  |
| Laravel    | `^13.0` |

## Install

```bash
composer require hitaqnia/huwiya-laravel
```

The service provider is registered automatically through Laravel's package discovery.

Publish the configuration file if you need to customize settings beyond environment variables:

```bash
php artisan vendor:publish --tag=huwiya-config
```

## Configure

Add your Huwiya credentials to `.env`:

```dotenv
HUWIYA_PROJECT_ID=your-project-id
HUWIYA_CLIENT_ID=your-client-id
HUWIYA_CLIENT_SECRET=your-client-secret
```

Everything else has a sensible default. See [configuration](02-configuration.md) for the full list.

> The default redirect URI is derived from `APP_URL`. Make sure `APP_URL` matches the host registered with the IdP, or set `HUWIYA_REDIRECT_URI` explicitly.

## Prepare the User model

Add the trait:

```php
use Huwiya\InteractsWithHuwiya;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use InteractsWithHuwiya;
}
```

The trait is mandatory — both guards throw `AuthConfigurationException` if the provider model does not use it.

## Write the migration

The SDK ships a single Blueprint macro, `huwiyaIdentifier()`, which creates the Huwiya subject column (nullable unique ULID). Compose it alongside any other columns your application needs:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->huwiyaIdentifier();               // nullable unique ULID
            $table->string('phone')->unique();
            $table->string('email')->unique()->nullable();
            $table->string('name')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
```

`huwiyaIdentifier()` defaults to a column named `huwiya_id`. Pass a string to rename it — `$table->huwiyaIdentifier('sso_id')` — and override `getHuwiyaIdentifierColumn()` on the model to match.

The column is nullable so application code may pre-seed rows (e.g. for invitations) before they are linked to a Huwiya subject. The unique constraint prevents duplicates, and NULLs are free under the major database engines (MySQL, PostgreSQL, SQLite all allow multiple NULLs in a UNIQUE index).

## Register the guard

Switch the `web` and/or `api` guards in `config/auth.php` to the drivers provided by the package:

```php
'guards' => [
    'web' => ['driver' => 'huwiya-web', 'provider' => 'users'],
    'api' => ['driver' => 'huwiya-api', 'provider' => 'users'],
],
```

## Start a login

The SDK does not ship a login route. Create one that calls `Huwiya::redirect($guard)`:

```php
use Huwiya\Facades\Huwiya;

Route::get('/login', fn () => Huwiya::redirect('web'))->name('login');
```

Optionally pass an intended post-login URL:

```php
Route::get('/login', fn () => Huwiya::redirect('web', '/dashboard'));
```

`Huwiya::redirect()` validates the guard, stores the guard and intended URL atomically alongside the OAuth `state` in the session, and redirects to the IdP. The callback route `/huwiya/callback` is registered automatically, rate-limited at 30 requests per minute per IP.

Register the callback URL with the IdP as an allowed redirect URI and you're done.

## Next steps

- [Customization](03-customization.md) — what to override on your User model.
- [Extensions](04-extensions.md) — patterns for invitations, phone recycling, tenant scoping.
- [Errors](06-errors.md) — how the SDK surfaces token rejection and misconfiguration.
