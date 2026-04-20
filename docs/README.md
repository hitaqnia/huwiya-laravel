# Documentation

The official documentation for the Huwiya Laravel SDK.

## Table of contents

- [Getting started](getting-started.md) — install, migrate, add the trait, register a guard, start a login.
- [Configuration](configuration.md) — environment variables and the full `config/huwiya.php` reference.
- [Customization](customization.md) — the policy hooks you override on your User model and when to use each.
- [Extensions](extensions.md) — application-level recipes that compose the policy hooks into common patterns (invitations, phone recycling).
- [Events](events.md) — lifecycle events and example listeners.
- [Errors](errors.md) — the Result pattern for token rejection, the exception hierarchy, and callback HTTP status codes.
- [Security](security.md) — security properties and defaults.
- [Testing](testing.md) — authenticating users in tests without the OAuth flow.

## Where to start

- **First install?** Read [getting-started](getting-started.md) end to end, then skim [customization](customization.md).
- **Invite-only onboarding? Phone recycling?** See [extensions](extensions.md).
- **Debugging a token rejection?** See [errors](errors.md).
