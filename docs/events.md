# Events

The SDK dispatches lifecycle events from inside the `InteractsWithHuwiya` trait. Both the web (OAuth callback) and API (JWT bearer) flows fire them consistently. Every event carries the `TokenClaims`, the `Authenticatable` user (when available), and the guard name.

All event classes live under the `Huwiya\Events` namespace.

| Event                   | Payload                        | Fired when                                                                          |
| ----------------------- | ------------------------------ | ----------------------------------------------------------------------------------- |
| `HuwiyaAuthenticating`  | `$claims`, `$guard`            | Before any database work.                                                           |
| `HuwiyaUserResolving`   | `$claims`, `$query`, `$guard`  | Before the lookup query executes. Listeners may further constrain `$query`.         |
| `HuwiyaUserCreating`    | `$claims`, `$guard`            | Before a new user is created.                                                       |
| `HuwiyaUserCreated`     | `$claims`, `$user`, `$guard`   | After a new user has been created.                                                  |
| `HuwiyaUserUpdating`    | `$claims`, `$user`, `$guard`   | Before an existing user is updated.                                                 |
| `HuwiyaUserUpdated`     | `$claims`, `$user`, `$guard`   | After an existing user has been updated.                                            |
| `HuwiyaAuthenticated`   | `$claims`, `$user`, `$guard`   | After the user has been resolved (created or updated). Fires on every sign-in.      |

## Examples

### Welcome email on first login

```php
use Huwiya\Events\HuwiyaUserCreated;
use Illuminate\Support\Facades\Event;

Event::listen(HuwiyaUserCreated::class, function (HuwiyaUserCreated $event) {
    Mail::to($event->user)->queue(new WelcomeMail($event->user));
});
```

### Track last login on every sign-in

```php
use Huwiya\Events\HuwiyaAuthenticated;

Event::listen(HuwiyaAuthenticated::class, function (HuwiyaAuthenticated $event) {
    $event->user->update(['last_login_at' => now()]);
});
```

### Scope lookups to a tenant via a listener

```php
use Huwiya\Events\HuwiyaUserResolving;

Event::listen(HuwiyaUserResolving::class, function (HuwiyaUserResolving $event) {
    $event->query->where('tenant_id', session('tenant_id'));
});
```

## When to use an event vs a trait override

- **Event listener** — cross-cutting side effects (emails, audit logs, analytics, role sync) that aren't a per-model decision.
- **Trait override** — decisions about *this user model's* behavior (what to persist, how to look up, what policy to apply).

If your logic touches multiple models, or needs to be swapped out per environment, prefer a listener. If it's the core rule of how this one model interacts with Huwiya, prefer an override.
