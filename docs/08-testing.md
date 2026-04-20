# Testing

## Authenticate without the OAuth flow

```php
use Huwiya\Facades\Huwiya;

Huwiya::actingAs($user, 'web');
Huwiya::actingAs($user, 'api');
```

This sets the user on the specified guard and registers the guard as the default for the remainder of the test.

## Run the package's own suite

```bash
composer test
```
