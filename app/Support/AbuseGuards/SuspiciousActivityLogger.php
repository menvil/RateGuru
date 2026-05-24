<?php

namespace App\Support\AbuseGuards;

use App\Models\User;

final class SuspiciousActivityLogger
{
    public function record(
        string $event,
        ?User $user = null,
        array $context = [],
    ): void {
        // No-op placeholder for future structured abuse logging.
    }
}
