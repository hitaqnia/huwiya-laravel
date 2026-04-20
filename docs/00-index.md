# Documentation

The official documentation for the Huwiya Laravel SDK.

## Table of contents

- [01 · Getting started](01-getting-started.md) — install, migrate, add the trait, register a guard, start a login.
- [02 · Configuration](02-configuration.md) — environment variables and the full `config/huwiya.php` reference.
- [03 · Customization](03-customization.md) — the policy hooks you override on your User model and when to use each.
- [04 · Extensions](04-extensions.md) — application-level recipes that compose the policy hooks into common patterns (invitations, phone recycling).
- [05 · Events](05-events.md) — lifecycle events and example listeners.
- [06 · Errors](06-errors.md) — the Result pattern for token rejection, the exception hierarchy, and callback HTTP status codes.
- [07 · Security](07-security.md) — security properties and defaults.
- [08 · Testing](08-testing.md) — authenticating users in tests without the OAuth flow.

## Where to start

- **First install?** Read [01 · Getting started](01-getting-started.md) end to end, then skim [03 · Customization](03-customization.md).
- **Invite-only onboarding? Phone recycling?** See [04 · Extensions](04-extensions.md).
- **Debugging a token rejection?** See [06 · Errors](06-errors.md).
