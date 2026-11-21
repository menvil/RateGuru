<?php

namespace App\Actions\Analytics;

use App\Actions\Traffic\MarkTrafficVisitAction;
use App\Enums\TrafficEvent;
use App\Enums\UserActivity;
use App\Enums\UserRole;
use App\Models\AccountAttribution;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Models\UserActivityDay;
use App\Support\Analytics\ActivityDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A member did something on the site that counts as coming back with an
 * action (UserActivity), called by the screens a person acts on once what
 * they did is in (docs/architecture/traffic.md, "Accounts"). The UTC day gets
 * its row in `user_activity_days`, once, with the thing's flag set — a second
 * thing the same day sets its own flag on the same row, and the moment of
 * the first stays. A Persona or a member of staff records nothing.
 *
 * Doing something on one of the days after the day of signing up — day 1 to
 * `analytics.activation_days` — activates a new account, once: its
 * attribution gets `activated_at` under a lock on it, and a confirmed
 * account's credited visit its Activated mark, which is counted and never
 * told to a network. An account not confirmed yet gets the mark when it is
 * (ConfirmAccountAttributionAction).
 *
 * Each new vote, comment and post also counts on the visit of the browser
 * it was done from, while visits are recorded (RecordVisitPostAction::
 * visitFor()) — every one, not once a day — the first of them on the visit
 * keeps its moment there, and a vote or a comment sets its flag on that
 * visit's row of the post. The screens call this only for
 * a new vote: one taken back or turned the other way is none.
 */
final class RecordUserActivityAction
{
    public function __construct(
        private readonly MarkTrafficVisitAction $marks,
        private readonly RecordVisitPostAction $posts,
        private readonly MarkAnalyticsDayAction $analytics,
    ) {}

    /**
     * From the screen, after what the member did is in: in a transaction of
     * its own — a savepoint inside one already open — so a failure here is
     * reported and never undoes or spoils what they did.
     */
    public function quietly(User $user, UserActivity $activity, ?int $postId, ?string $browserHash, CarbonImmutable $at): void
    {
        try {
            DB::transaction(fn () => $this->handle($user, $activity, $postId, $browserHash, $at));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  int|null  $postId  the post it was done on: voted on, commented on, made
     * @param  string|null  $browserHash  the browser it was done from, when it holds the visitor cookie
     */
    public function handle(User $user, UserActivity $activity, ?int $postId, ?string $browserHash, CarbonImmutable $at): void
    {
        if ($user->role !== UserRole::User) {
            return;
        }

        $this->recordDay($user, $activity, $at);

        if ($browserHash === null) {
            return;
        }

        // In a savepoint of its own: the day is recorded whatever happens to the visit's count.
        try {
            DB::transaction(fn () => $this->countOnVisit($activity, $postId, $browserHash, $at));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function recordDay(User $user, UserActivity $activity, CarbonImmutable $at): void
    {
        $day = ActivityDay::of($at);

        // Already done today: the row and the activation it could bring were both decided then.
        if (UserActivityDay::query()->where('user_id', $user->id)->where('day', $day)->where($activity->column(), true)->exists()) {
            return;
        }

        DB::transaction(function () use ($user, $activity, $at, $day): void {
            $attribution = AccountAttribution::query()->where('user_id', $user->id)->first();
            $signedUpAt = $attribution !== null ? $attribution->signed_up_at : ($user->created_at === null ? null : CarbonImmutable::instance($user->created_at));
            $number = $signedUpAt === null ? null : ActivityDay::number($signedUpAt, $at);
            // Nothing is done before the day an account signs up; a clock that says otherwise leaves the day unnumbered.
            $number = $number !== null && $number >= 0 ? $number : null;

            $flags = [];

            foreach (UserActivity::cases() as $case) {
                $flags[$case->column()] = $case === $activity;
            }

            // One row per member and day on every engine, however many requests race to it:
            // the first inserts it, the rest only set their own flag.
            UserActivityDay::query()->upsert(
                [['user_id' => $user->id, 'day' => $day, 'day_number' => $number, ...$flags, 'first_at' => $at->utc()]],
                ['user_id', 'day'],
                [$activity->column()],
            );

            if ($attribution !== null && $attribution->activated_at === null
                && ActivityDay::activates($number, (int) config('analytics.activation_days'))) {
                $this->activate((int) $attribution->id, $at);
            }
        });
    }

    /** One more of the thing on the browser's visit, and the flag of its row of the post. */
    private function countOnVisit(UserActivity $activity, ?int $postId, string $browserHash, CarbonImmutable $at): void
    {
        $counter = $activity->visitCounter();

        if ($counter === null) {
            return;
        }

        $visit = $this->posts->visitFor($browserHash, $at);

        if ($visit === null) {
            return;
        }

        // The moment of the first, kept — a journey draws what the member did then — and only of the first: a visit
        // that counted some before the moment was kept keeps none rather than the moment of a later one.
        TrafficVisit::query()->whereKey($visit->id)->whereNull('acted_at')
            ->where('votes', 0)->where('comments', 0)->where('posts_created', 0)
            ->update(['acted_at' => $at->utc()]);
        TrafficVisit::query()->whereKey($visit->id)->where($counter, '<', RecordVisitPostAction::MOST)->increment($counter);

        if ($postId !== null) {
            $this->posts->actedOn((int) $visit->id, $postId, $activity, $at);
        }

        // The visit counted on can be a paid click days old: Analytics reads its day again — its counts and its posts
        // both written by now, so a run that reads the day misses neither.
        $this->analytics->handle($visit->network_day);
    }

    private function activate(int $attributionId, CarbonImmutable $at): void
    {
        $attribution = AccountAttribution::query()->whereKey($attributionId)->lockForUpdate()->first();

        if ($attribution === null || $attribution->activated_at !== null) {
            return;
        }

        $attribution->forceFill(['activated_at' => $at])->save();

        if ($attribution->confirmed_at !== null && $attribution->credited_visit_id !== null) {
            $this->marks->handle((int) $attribution->credited_visit_id, TrafficEvent::Activated, $at);
        }
    }
}
