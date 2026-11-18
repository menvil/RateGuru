<?php

namespace App\Actions\Traffic;

use App\Actions\Games\GameMilestoneRecorder;
use App\Enums\GameFlowGoal;
use App\Enums\TrafficEvent;
use App\Enums\TrafficKnownReason;
use App\Models\AccountAttribution;
use App\Models\GameAttempt;
use App\Models\User;
use App\Queries\Traffic\AccountCreditQuery;
use App\Queries\Traffic\VisitorVisitQuery;
use App\Support\Games\Play\GameVisitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Somebody signs in to an account they already had. The browser is theirs
 * from now on; the visit it is on — the paid click holding it, else its
 * latest visit of the day the tracker counts, before a visit to the rest of
 * the site such as the sign-in page itself — unless it is the one their own
 * new account was credited to or a known visitor's already, turns out to be a
 * known visitor's — it brings no account, and is worth the price of a
 * sign-in (MarkTrafficVisitAction works it out). A game they finished in
 * this browser within 7 days reaches the goal "Account confirmed" when the
 * account is: an existing account counts for it, not for "New accounts only".
 */
final class RecordAccountLoginAction
{
    private const int WINDOW_DAYS = 7;

    public function __construct(
        private readonly AccountCreditQuery $credits,
        private readonly VisitorVisitQuery $visits,
        private readonly LinkAccountBrowserAction $browsers,
        private readonly MarkTrafficVisitAction $marks,
        private readonly GameMilestoneRecorder $milestones,
    ) {}

    public function handle(User $user, ?string $browserHash, CarbonImmutable $now): void
    {
        if ($browserHash === null) {
            return;
        }

        DB::transaction(function () use ($user, $browserHash, $now): void {
            $this->browsers->handle((int) $user->id, $browserHash, $now);
            $attribution = AccountAttribution::query()->where('user_id', $user->id)->first();
            $visit = $this->visits->holding($browserHash, $now) ?? $this->visits->latestTrackedFirst($browserHash, $now->subDay());

            // A visit that brought an account — this one's or another's on the same browser — stays that account's: a sign-in doesn't make it a known visitor's.
            // One known as it came stays known for its own reason: its visitor was never new, and signing in again is worth nothing.
            if ($visit !== null && ! $visit->known && $visit->signup_at === null && $visit->account_at === null
                && ! AccountAttribution::query()->where('credited_visit_id', $visit->id)->exists()) {
                $this->marks->handle((int) $visit->id, TrafficEvent::Login, $now, ['known' => true, 'known_reason' => TrafficKnownReason::Login->value]);
            }

            if (! $user->hasVerifiedEmail() && ! $user->socialAccounts()->exists()) {
                return;
            }

            $found = $this->credits->attempt($browserHash, (int) $user->id, $now->subDays(self::WINDOW_DAYS), (int) $user->id);
            $attempt = $found === null || $found->id === $attribution?->credited_attempt_id ? null : GameAttempt::query()->whereKey($found->id)->lockForUpdate()->first();

            if ($attempt !== null && $attempt->version->flowGoal() === GameFlowGoal::Account) {
                $this->milestones->markGoal($attempt, new GameVisitor($attempt->audience, (int) $user->id, $attempt->visitor_hash), GameFlowGoal::Account);
            }
        });
    }
}
