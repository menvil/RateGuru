<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Log;

final class SlowActionLogger
{
    public function __construct(private readonly LogContext $logContext) {}

    public function measure(string $name, callable $callback, ?int $thresholdMs = null, array $context = []): mixed
    {
        $threshold = $thresholdMs ?? (int) config('observability.slow_actions.default_threshold_ms', 500);
        $start = hrtime(true);

        try {
            return $callback();
        } finally {
            $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);

            if ($durationMs >= $threshold) {
                Log::warning($name.'.slow', array_merge(
                    $this->logContext->base(),
                    $context,
                    [
                        'duration_ms' => $durationMs,
                        'threshold_ms' => $threshold,
                    ],
                ));
            }
        }
    }
}
