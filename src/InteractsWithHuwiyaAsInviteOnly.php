<?php

namespace Huwiya;

/**
 * Convenience trait for the common "invite-only" install: no auto-registration,
 * phone-fallback lookup enabled. Users are pre-seeded with a phone (no huwiya_id)
 * and claim their row on first Huwiya login.
 *
 * Usage:
 *
 *     class User extends Authenticatable {
 *         use InteractsWithHuwiyaAsInviteOnly;
 *     }
 *
 * All other extension points (field map, lifecycle hooks, lookup overrides)
 * are inherited from `InteractsWithHuwiya` unchanged.
 */
trait InteractsWithHuwiyaAsInviteOnly
{
    use InteractsWithHuwiya;

    public function shouldAutoRegister(?TokenClaims $claims = null): bool
    {
        return false;
    }

    public function invitationsEnabled(): bool
    {
        return true;
    }
}
