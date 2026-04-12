# Huwiya Laravel SDK

A Laravel package for integrating with the Huwiya Identity Provider. Provides OAuth authentication, JWT token verification, and user management for Laravel applications.

## Requirements

- PHP 8.3+
- Laravel 13.0+

## Installation

```bash
composer require huwiya/huwiya
```

The service provider is automatically registered via Laravel's package discovery.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Hawia\HawiaServiceProvider" --tag="huwiya-config"
```

Set the following environment variables:

```env
HUWIYA_IDP_URL=https://your-idp-url.com
HUWIYA_CLIENT_ID=your-client-id
HUWIYA_CLIENT_SECRET=your-client-secret
HUWIYA_REDIRECT_URI=https://your-app.com/huwiya/callback
```

## Usage

### User Model

Add the `HasHawiaTokens` trait to your User model:

```php
use Hawia\HasHawiaTokens;

class User extends Authenticatable
{
    use HasHawiaTokens;

    protected $fillable = ['name', 'hawia_id', 'phone'];
}
```

### Authentication Guards

The package registers two authentication guards:

- **`hawia-web`** — Session-based authentication for web requests
- **`hawia-api`** — Stateless JWT bearer token authentication for API requests

### Middleware

Use the `EnsureFrontendRequestsAreStateful` middleware for SPA authentication:

```php
use Hawia\Http\Middleware\EnsureFrontendRequestsAreStateful;
```

## Testing

```bash
composer test
```

## License

MIT License. See [LICENSE](LICENSE) for details.
