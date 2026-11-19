<?php

namespace App\Queries\Traffic;

use App\Enums\GameAttemptStatus;
use App\Models\AccountAttribution;
use App\Models\GameAttempt;
use Carbon\CarbonImmutable;

/**
 * The play an account is credited to: the last game this browser finished
 * since a moment — as a guest, or already claimed by this account — that no
 * other account was credited to. One play brings one account.
 */
final class AccountCreditQuery
{
    public function attempt(string $browserHash, int $userId, CarbonImmutable $since, ?int $exceptAttributionOf = null): ?GameAttempt
    {
        return GameAttempt::query()
            ->where('visitor_hash', $browserHash)
            ->where('status', GameAttemptStatus::Completed)
            ->where('completed_at', '>=', $since)
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $userId))
            ->whereNotIn('id', AccountAttribution::query()
                ->whereNotNull('credited_attempt_id')
                ->when($exceptAttributionOf !== null, fn ($query) => $query->where('user_id', '!=', $exceptAttributionOf))
                ->select('credited_attempt_id'))
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
    }
}
