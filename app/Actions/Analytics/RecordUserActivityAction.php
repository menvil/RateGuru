<?php

namespace App\Actions\Analytics;

use App\Actions\Traffic\MarkTrafficVisitAction;
use App\Enums\TrafficEvent;
use App\Enums\UserActivity;
use App\Enums\UserRole;
use App\Models\AccountAttribution;
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
 */
final class RecordUserActivityAction
{
    public function __construct(private readonly MarkTrafficVisitAction $marks) {}

    /**
     * From the screen, after what the member did is in: in a transaction of
     * its own — a savepoint inside one already open — so a failure here is
     * reported and never undoes or spoils what they did.
     */
    public function quietly(User $user, UserActivity $activity, CarbonImmutable $at): void
    {
        try {
            DB::transaction(fn () => $this->handle($user, $activity, $at));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function handle(User $user, UserActivity $activity, CarbonImmutable $at): void
    {
        if ($user->role !== UserRole::User) {
            return;
        }

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
