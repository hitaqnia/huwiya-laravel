<?php

namespace Huwiya\Support;

use InvalidArgumentException;

/**
 * @template T
 */
class Result
{
    protected bool $isSuccess = true;

    /**
     * @var T
     */
    protected mixed $data = null;

    protected ?Error $error = null;

    /**
     * @param  ?T  $data
     *
     * @throws InvalidArgumentException
     */
    public function __construct(bool $isSuccess, $data = null, ?Error $error = null)
    {
        if ($isSuccess && $error) {
            throw new InvalidArgumentException('Error must be null if success is true');
        }

        if (! $isSuccess && ! $error) {
            throw new InvalidArgumentException('You must provide an error if the operation failed');
        }

        $this->isSuccess = $isSuccess;
        $this->data = $data;
        $this->error = $error;
    }

    /**
     * @template S
     *
     * @param  ?S  $data
     * @return Result<S>
     */
    public static function success($data = null): self
    {
        return new self(isSuccess: true, data: $data);
    }

    /**
     * @return Result<null>
     */
    public static function failure(Error $error): self
    {
        return new self(false, null, $error);
    }

    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    public function isFailure(): bool
    {
        return ! $this->isSuccess;
    }

    /**
     * @return T
     */
    public function getData()
    {
        return $this->data;
    }

    public function getError(): ?Error
    {
        return $this->error;
    }
}
