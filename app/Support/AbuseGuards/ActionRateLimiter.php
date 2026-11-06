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
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw RateLimitExceededException::make(
                message: $message ?? __('ui.rate_limit.default'),
                retryAfterSeconds: RateLimiter::availableIn($key),
            );
        }

        RateLimiter::hit($key, $decaySeconds);
    }
}
