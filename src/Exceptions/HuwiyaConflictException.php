<?php

namespace Huwiya\Exceptions;

use Huwiya\TokenClaims;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Raised when persisting claim data to the local users table hits a
 * unique-constraint violation (typically on phone or email) that the
 * model's `resolveHuwiyaConflict` policy did not clear.
 *
 * The exception carries enough context for callers to build a support
 * flow, emit a structured audit event, or render a custom error page.
 */
class HuwiyaConflictException extends HuwiyaException
{
    public function __construct(
        string $message,
        public readonly TokenClaims $claims,
        public readonly ?Authenticatable $existingRow = null,
        public readonly ?string $conflictingColumn = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Raised by the default resolveHuwiyaConflict policy.
     */
    public static function make(
        TokenClaims $claims,
        Authenticatable $existingRow,
        string $conflictingColumn,
    ): self {
        return new self(
            sprintf(
                'The %s [%s] is already associated with another account.',
                $conflictingColumn,
                (string) $claims->{$conflictingColumn},
            ),
            $claims,
            $existingRow,
            $conflictingColumn,
        );
    }

    /**
     * Raised when the app's policy ran but the retry still hit a conflict,
     * or when the SDK could not identify the colliding column.
     */
    public static function unresolved(TokenClaims $claims, Throwable $previous): self
    {
        return new self(
            'Claim data conflicts with an existing user and the conflict policy did not resolve it.',
            $claims,
            null,
            null,
            $previous,
        );
    }
}
