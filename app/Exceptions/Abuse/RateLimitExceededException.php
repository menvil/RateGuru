<?php

namespace App\Exceptions\Abuse;

use DomainException;

final class RateLimitExceededException extends DomainException
{
    public function __construct(
        string $message,
        private readonly int $retryAfterSeconds,
    ) {
        parent::__construct($message);
    }

    public static function make(string $message, int $retryAfterSeconds): self
    {
        return new self($message, $retryAfterSeconds);
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
