# Huwiya SDK for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hitaqnia/huwiya-laravel.svg?style=flat-square)](https://packagist.org/packages/hitaqnia/huwiya-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/hitaqnia/huwiya-laravel.svg?style=flat-square)](https://packagist.org/packages/hitaqnia/huwiya-laravel)
[![License](https://img.shields.io/packagist/l/hitaqnia/huwiya-laravel.svg?style=flat-square)](LICENSE)

The official Laravel SDK for the [Huwiya](https://huwiya.id) Identity Provider. It integrates Huwiya-issued JWTs with Laravel's native guard system through two first-class drivers:

- **`huwiya-web`** — OAuth 2.0 Authorization Code flow for session-based web applications.
- **`huwiya-api`** — JWT Bearer authentication for stateless APIs.

## Install

```bash
composer require hitaqnia/huwiya-laravel
```

Add credentials to `.env`:

```dotenv
HUWIYA_PROJECT_ID=your-project-id
HUWIYA_CLIENT_ID=your-client-id
HUWIYA_CLIENT_SECRET=your-client-secret
```

Add the trait to your User model, write your migration using the `huwiyaIdentifier()` macro, register the guard, and wire a login route. Full walkthrough in [docs/getting-started.md](docs/getting-started.md).

## Documentation

Full documentation lives in the [`docs/`](docs/README.md) directory.

- [Getting started](docs/getting-started.md) — install, migrate, register a guard, start a login.
- [Configuration](docs/configuration.md) — environment variables and config reference.
- [Customization](docs/customization.md) — the policy hooks you override on your User model.
- [Extensions](docs/extensions.md) — recipes for invitations, phone recycling, tenant scoping, role sync.
- [Events](docs/events.md) — lifecycle events and listener examples.
- [Errors](docs/errors.md) — the Result pattern, exceptions, and HTTP status codes.
- [Security](docs/security.md) — security properties and defaults.
- [Testing](docs/testing.md) — authenticating users in tests.

## Requirements

| Dependency | Version |
| ---------- | ------- |
| PHP        | `^8.3`  |
| Laravel    | `^13.0` |

## Contributing

Bug reports, feature requests, and pull requests are welcome on [GitHub](https://github.com/hitaqnia/huwiya-laravel). Please run the test suite before submitting a pull request:

```bash
composer test
```

## Security

If you discover a security vulnerability, email **info@hitaqnia.com** rather than opening a public issue.

## License

Released under the [MIT License](LICENSE).
