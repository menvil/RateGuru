<?php

namespace App\Actions\Traffic;

use App\Actions\Analytics\MarkAnalyticsPeriodHourAction;
use App\Actions\Games\GameEventRecorder;
use App\Actions\Games\GameMilestoneRecorder;
use App\Enums\GameEventType;
use App\Enums\GameFlowGoal;
use App\Enums\TrafficEvent;
use App\Models\AccountAttribution;
use App\Models\GameAttempt;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Support\Games\Play\GameVisitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A new account confirmed — its email, or Google or Facebook — counted once,
 * for what it was credited to when it signed up: the play gets
 * `account_confirmed` and, when the flow's goal is an account, its goal; the
 * visit gets its account, and its activation when the account was activated
 * before it was confirmed (RecordUserActivityAction). Every way of confirming
 * calls it, and only the first does anything.
 */
final class ConfirmAccountAttributionAction
{
    public function __construct(
        private readonly GameEventRecorder $events,
        private readonly GameMilestoneRecorder $milestones,
        private readonly MarkTrafficVisitAction $marks,
        private readonly MarkAnalyticsPeriodHourAction $periodHours,
    ) {}

    public function handle(int $userId, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($userId, $now): void {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            if ($user === null || (! $user->hasVerifiedEmail() && ! $user->socialAccounts()->exists())) {
                return;
            }

            $attemptId = AccountAttribution::query()->where('user_id', $userId)->value('credited_attempt_id');
            $attempt = $attemptId === null ? null : GameAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();
            $attribution = AccountAttribution::query()->where('user_id', $userId)->lockForUpdate()->first();

            if ($attribution === null || $attribution->confirmed_at !== null) {
                return;
            }

            $attribution->forceFill(['confirmed_at' => $now])->save();

            if ($attempt !== null) {
                // An account of the play's period's funnel from now on.
                $this->periodHours->ofAttempt($attempt);
                $visitor = new GameVisitor($attempt->audience, $userId, $attempt->visitor_hash);
                $this->events->record(GameEventType::AccountConfirmed, (int) $attempt->game_id, $visitor, attempt: $attempt);
                $goal = $attempt->version->flowGoal();

                if (in_array($goal, [GameFlowGoal::Account, GameFlowGoal::NewAccount], true)) {
                    $this->milestones->markGoal($attempt, $visitor, $goal);
                }
            }

            $visit = $attribution->credited_visit_id === null ? null : TrafficVisit::query()->find($attribution->credited_visit_id);

            if ($visit !== null) {
                $this->marks->handle((int) $visit->id, TrafficEvent::Account, $now, [
                    'account_after_seconds' => max(0, (int) $visit->arrived_at->diffInSeconds($now, true)),
                ]);

                // Activated before it was confirmed: the visit is marked now, beside its account,
                // and keeps the moment the account was activated.
                if ($attribution->activated_at !== null) {
                    $this->marks->handle((int) $visit->id, TrafficEvent::Activated, $now, ['activated_at' => $attribution->activated_at]);
                }
            }
        });
    }
}
