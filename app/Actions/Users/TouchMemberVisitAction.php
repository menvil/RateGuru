<?php

namespace App\Actions\Users;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A member seen on the site: last_seen_at moves on, and a gap of more than an hour since it was last moved starts a
 * new visit — previous_visit_at keeps where the last one ended, what the feed counts a post in their language as
 * fresh from (FreshWindow). Kept on the account, not the session, so a second device and a tab left open for days
 * both see the visit the member is on.
 *
 * Two conditional statements on the member's row, outside any transaction and without a lock: of two requests
 * racing to start the same visit, the second finds last_seen_at already moved and changes nothing.
 */
final class TouchMemberVisitAction
{
    /** A member away for longer than this is on a new visit when they come back. */
    public const VISIT_GAP_MINUTES = 60;

    public function handle(User $user, CarbonImmutable $now): void
    {
        $seen = $user->last_seen_at === null ? null : CarbonImmutable::instance($user->last_seen_at);

        if ($seen === null || $seen->lessThan($now->subMinutes(self::VISIT_GAP_MINUTES))) {
            $started = User::withoutTimestamps(fn (): int => User::query()
                ->whereKey($user->id)
                ->when($seen === null, fn ($query) => $query->whereNull('last_seen_at'), fn ($query) => $query->where('last_seen_at', $seen))
                ->update(['previous_visit_at' => $seen, 'last_seen_at' => $now]));

            if ($started > 0) {
                $this->remember($user, $seen, $now);

                return;
            }

            // Another request started the visit first: the rest of this one reads the visit it started.
            $winner = User::query()->whereKey($user->id)->first(['id', 'last_seen_at', 'previous_visit_at']);
            $user->forceFill(['last_seen_at' => $winner?->last_seen_at, 'previous_visit_at' => $winner?->previous_visit_at])
                ->syncOriginalAttributes(['previous_visit_at', 'last_seen_at']);

            return;
        }

        User::withoutTimestamps(fn (): int => User::query()
            ->whereKey($user->id)
            ->where('last_seen_at', '<', $now)
            ->update(['last_seen_at' => $now]));

        $this->remember($user, $user->previous_visit_at === null ? null : CarbonImmutable::instance($user->previous_visit_at), $now);
    }

    /** The signed-in member as the rest of the request reads it, never written again by a later save. */
    private function remember(User $user, ?CarbonImmutable $previous, CarbonImmutable $now): void
    {
        $user->forceFill(['previous_visit_at' => $previous, 'last_seen_at' => $now])->syncOriginalAttributes(['previous_visit_at', 'last_seen_at']);
    }
}
