<?php

namespace App\Support\AbuseGuards;

use App\Exceptions\Abuse\RateLimitExceededException;
use Illuminate\Support\Facades\RateLimiter;

final class ActionRateLimiter
{
    public function hitOrFail(
        string $key,
        int $maxAttempts,
        int $decaySeconds,
        ?string $message = null,
    ): void {
        // Counted first, then judged: the increment is one atomic cache
        // operation, so of several requests arriving together only the ones
        // within the limit see a count that admits them. A check followed by
        // a separate hit let all of them read the same count and pass.
        if (RateLimiter::hit($key, $decaySeconds) > $maxAttempts) {
            throw RateLimitExceededException::make(
                message: $message ?? __('ui.rate_limit.default'),
                retryAfterSeconds: RateLimiter::availableIn($key),
            );
        }
    }
}
