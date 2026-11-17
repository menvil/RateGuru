<?php

namespace App\Actions\Traffic;

use App\Enums\AccountSignupMethod;
use App\Enums\AccountSignupPlace;
use App\Enums\TrafficEvent;
use App\Models\AccountAttribution;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Queries\Traffic\AccountCreditQuery;
use App\Queries\Traffic\VisitorVisitQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A new account, as it signs up: what it will be credited to once confirmed
 * — decided now, since the confirmation may come days later on another
 * device. The last game this browser finished within 7 days, and the paid
 * click holding the browser, else its unpaid visit of the last 7 days. With
 * no click holding it but one whose window closed, that click is told the
 * account came late, and the account goes to the unpaid visit or to nobody.
 * An account signed up through Google or Facebook is confirmed at once.
 */
final class RecordAccountSignupAction
{
    private const int WINDOW_DAYS = 7;

    public function __construct(
        private readonly AccountCreditQuery $credits,
        private readonly VisitorVisitQuery $visits,
        private readonly LinkAccountBrowserAction $browsers,
        private readonly MarkTrafficVisitAction $marks,
        private readonly ConfirmAccountAttributionAction $confirm,
    ) {}

    public function handle(User $user, ?string $browserHash, AccountSignupMethod $method, AccountSignupPlace $place, CarbonImmutable $now): void
    {
        $recorded = DB::transaction(function () use ($user, $browserHash, $method, $place, $now): bool {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->canSignIn() || AccountAttribution::query()->where('user_id', $locked->id)->exists()) {
                return false;
            }

            $since = $now->subDays(self::WINDOW_DAYS);
            $attempt = $browserHash === null ? null : $this->credits->attempt($browserHash, (int) $locked->id, $since);
            $holding = $browserHash === null ? null : $this->visits->holding($browserHash, $now);
            $expired = $holding === null && $browserHash !== null ? $this->visits->latestExpiredPaid($browserHash, $now) : null;
            $visit = $holding ?? ($browserHash === null ? null : $this->visits->latestUnpaid($browserHash, $since));

            AccountAttribution::query()->create([
                'user_id' => $locked->id,
                'visitor_hash' => $browserHash,
                'signup_method' => $method,
                'signup_place' => $place,
                'signed_up_at' => $now,
                'credited_attempt_id' => $attempt?->id,
                'credited_visit_id' => $visit?->id,
                'expired_visit_id' => $expired?->id,
                'late' => $expired !== null,
            ]);

            if ($browserHash !== null) {
                $this->browsers->handle((int) $locked->id, $browserHash, $now);
            }

            if ($visit !== null) {
                $this->marks->handle((int) $visit->id, TrafficEvent::Signup, $now, [
                    'signup_place' => $place->value,
                    'signup_method' => $method->value,
                    'signup_after_seconds' => self::secondsSince($visit, $now),
                ]);
            }

            if ($expired !== null) {
                TrafficVisit::query()->whereKey($expired->id)->whereNull('late_signup_at')->update(['late_signup_at' => $now]);
            }

            return true;
        });

        if ($recorded && $method !== AccountSignupMethod::Email) {
            $this->confirm->handle((int) $user->id, $now);
        }
    }

    private static function secondsSince(TrafficVisit $visit, CarbonImmutable $now): int
    {
        return max(0, (int) $visit->arrived_at->diffInSeconds($now, true));
    }
}
