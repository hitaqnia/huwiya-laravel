<?php

namespace Huwiya\Support;

class Error
{
    public function __construct(
        protected int $code,
        protected ?string $message = null,
    ) {}

    public static function make(int $code, ?string $message = null): self
    {
        return new self($code, $message);
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }
}
