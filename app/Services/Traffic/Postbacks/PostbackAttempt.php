<?php

namespace App\Services\Traffic\Postbacks;

use App\Enums\TrafficPostbackOutcome;

/** What one attempt to send a postback came to, as the log keeps it. */
final readonly class PostbackAttempt
{
    public function __construct(
        public TrafficPostbackOutcome $outcome,
        public ?int $httpStatus,
        public ?int $latencyMs,
        public ?string $excerpt,
    ) {}
}
