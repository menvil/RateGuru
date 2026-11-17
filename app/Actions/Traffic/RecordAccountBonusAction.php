<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficEvent;
use App\Models\AccountAttribution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What a new account goes on to do that it is worth more for: its first
 * published post within 30 days of signing up, and coming back on another
 * day — not before 8 hours, not after 7 days. Each once, on the account's
 * record and on the visit it was credited to.
 */
final class RecordAccountBonusAction
{
    private const int POST_DAYS = 30;

    private const int RETURN_AFTER_HOURS = 8;

    private const int RETURN_WITHIN_DAYS = 7;

    public function __construct(private readonly MarkTrafficVisitAction $marks) {}

    /**
     * The first post, from inside the transaction that publishes it: in a
     * savepoint of its own, so a failure here is reported and never undoes
     * the post.
     */
    public function firstPostQuietly(int $authorId, CarbonImmutable $publishedAt): void
    {
        try {
            DB::transaction(fn () => $this->firstPost($authorId, $publishedAt));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function firstPost(int $authorId, CarbonImmutable $publishedAt): void
    {
        $attribution = AccountAttribution::query()->where('user_id', $authorId)->whereNull('first_post_at')->first();

        if ($attribution === null || $publishedAt->greaterThan($attribution->signed_up_at->addDays(self::POST_DAYS))) {
            return;
        }

        $this->record($attribution, 'first_post_at', TrafficEvent::PostPublished, $publishedAt);
    }

    public function returned(int $userId, CarbonImmutable $now): void
    {
        $attribution = AccountAttribution::query()->where('user_id', $userId)->whereNull('returned_at')->first();
        $signedUp = $attribution?->signed_up_at;

        if ($attribution === null || $signedUp === null
            || $now->utc()->toDateString() === $signedUp->utc()->toDateString()
            || $now->lessThan($signedUp->addHours(self::RETURN_AFTER_HOURS))
            || $now->greaterThan($signedUp->addDays(self::RETURN_WITHIN_DAYS))) {
            return;
        }

        $this->record($attribution, 'returned_at', TrafficEvent::Returned, $now);
    }

    private function record(AccountAttribution $attribution, string $column, TrafficEvent $event, CarbonImmutable $at): void
    {
        $first = AccountAttribution::query()->whereKey($attribution->id)->whereNull($column)->update([$column => $at]) === 1;

        if ($first && $attribution->credited_visit_id !== null) {
            $this->marks->handle((int) $attribution->credited_visit_id, $event, $at);
        }
    }
}
