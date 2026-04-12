<?php

namespace Huwiya\Support;

use Closure;
use ReflectionFunction;

/**
 * Container-scoped holder for the authorization-denied callback.
 *
 * Registered as a `scoped` singleton so long-running workers (Octane,
 * Swoole) reset it between requests. Prevents leaking a closure set by
 * one request into the next.
 */
class AuthorizationDeniedCallback
{
    /** @var callable|null */
    protected $callback = null;

    public function set(callable $callback): void
    {
        $this->callback = $callback;
    }

    public function isSet(): bool
    {
        return $this->callback !== null;
    }

    public function reset(): void
    {
        $this->callback = null;
    }

    /**
     * Invoke the registered callback. Passes `$error` and `$description`
     * only to callbacks that declare matching parameters, so existing
     * zero-arg closures continue to work unchanged.
     */
    public function invoke(?string $error = null, ?string $description = null): mixed
    {
        if ($this->callback === null) {
            return null;
        }

        $arity = $this->arity($this->callback);

        return match (true) {
            $arity >= 2 => ($this->callback)($error, $description),
            $arity === 1 => ($this->callback)($error),
            default => ($this->callback)(),
        };
    }

    protected function arity(callable $callback): int
    {
        if ($callback instanceof Closure) {
            return (new ReflectionFunction($callback))->getNumberOfParameters();
        }

        return 0;
    }
}
