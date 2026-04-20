# Huwiya SDK for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hitaqnia/huwiya-laravel.svg?style=flat-square)](https://packagist.org/packages/hitaqnia/huwiya-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/hitaqnia/huwiya-laravel.svg?style=flat-square)](https://packagist.org/packages/hitaqnia/huwiya-laravel)
[![License](https://img.shields.io/packagist/l/hitaqnia/huwiya-laravel.svg?style=flat-square)](LICENSE)

The official Laravel SDK for the [Huwiya](https://huwiya.id) Identity Provider. It integrates Huwiya-issued JWTs with Laravel's native guard system through two first-class drivers:

- **`huwiya-web`** — OAuth 2.0 Authorization Code flow for session-based web applications.
- **`huwiya-api`** — JWT Bearer authentication for stateless APIs.

## Documentation

Full documentation lives in the [`docs/`](docs/00-index.md) directory.

- [01 · Getting started](docs/01-getting-started.md) — install, migrate, register a guard, start a login.
- [02 · Configuration](docs/02-configuration.md) — environment variables and config reference.
- [03 · Customization](docs/03-customization.md) — the policy hooks you override on your User model.
- [04 · Extensions](docs/04-extensions.md) — recipes for invitations, phone recycling, tenant scoping, role sync.
- [05 · Events](docs/05-events.md) — lifecycle events and listener examples.
- [06 · Errors](docs/06-errors.md) — the Result pattern, exceptions, and HTTP status codes.
- [07 · Security](docs/07-security.md) — security properties and defaults.
- [08 · Testing](docs/08-testing.md) — authenticating users in tests.

## Contributing

Bug reports, feature requests, and pull requests are welcome on [GitHub](https://github.com/hitaqnia/huwiya-laravel). Please run the test suite before submitting a pull request:

```bash
composer test
```

## Security

If you discover a security vulnerability, email **info@hitaqnia.com** rather than opening a public issue.

## License

Released under the [MIT License](LICENSE).
