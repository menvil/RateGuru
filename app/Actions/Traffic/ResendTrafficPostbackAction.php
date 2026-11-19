<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficPostbackOutcome;
use App\Enums\TrafficPostbackStatus;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackAttempt;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisitDetail;
use App\Models\User;
use App\Services\Traffic\Postbacks\PostbackAttempt;
use App\Services\Traffic\Postbacks\PostbackDeliverer;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\Traffic\Postbacks\PostbackSchedule;
use App\Support\Traffic\Postbacks\PostbackUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sends a postback that failed, expired or was cancelled once more, now:
 * delivered when the network takes it, failed otherwise — with no retries
 * of its own. The address is the one the queue would have sent.
 */
final class ResendTrafficPostbackAction
{
    use LocksTrafficRecords;

    public function __construct(
        private readonly PostbackDeliverer $deliverer,
        private readonly ActionRateLimiter $limiter,
    ) {}

    /** @throws CannotManageTrafficException */
    public function handle(User $admin, TrafficPostback $postback): PostbackAttempt
    {
        $this->limiter->hitOrFail('traffic-resend:'.$admin->id, 30, 60);

        $claim = DB::transaction(function () use ($admin, $postback): array {
            $this->lockAdministrator($admin);

            // While postbacks are switched off nothing is sent to networks; a test postback stays available for setting a rule up.
            if (! TrafficSetting::current()->postbacks_enabled) {
                throw CannotManageTrafficException::because('Postbacks are switched off, so nothing is sent to networks. Switch on Send postbacks first.');
            }

            $locked = TrafficPostback::query()->whereKey($postback->id)->lockForUpdate()->with('conversion')->first();

            if ($locked === null) {
                throw CannotManageTrafficException::becauseItIsGone('This postback');
            }

            if (! in_array($locked->status, [TrafficPostbackStatus::Failed, TrafficPostbackStatus::Expired, TrafficPostbackStatus::Cancelled], true)) {
                throw CannotManageTrafficException::because('Only a postback that failed, expired or was cancelled can be sent again.');
            }

            $clickId = (string) TrafficVisitDetail::query()->whereKey($locked->conversion?->visit_id)->value('click_id');

            if ($clickId === '') {
                throw CannotManageTrafficException::because('The click’s ID is no longer kept, so the network can’t be told which click it was.');
            }

            // Held as long as a run's own claim, so the minute's send run leaves it alone while the resend is in flight.
            $locked->forceFill(['status' => TrafficPostbackStatus::Sending, 'attempts' => $locked->attempts + 1, 'claimed_at' => now(), 'resent_at' => now(), 'run_after' => now()->addSeconds(PostbackSchedule::CLAIM_SECONDS)])->save();

            return [
                'url' => PostbackUrl::withClickId($locked->url, $clickId),
                'rules' => TrafficSource::query()->whereKey($locked->source_id)->value('response_rules'),
                'attempts' => $locked->attempts,
            ];
        });

        $attempt = $this->deliverer->send([0 => ['url' => $claim['url'], 'rules' => is_array($claim['rules']) ? $claim['rules'] : null]])[0];
        $delivered = in_array($attempt->outcome, [TrafficPostbackOutcome::Ok, TrafficPostbackOutcome::Duplicate], true);

        DB::transaction(function () use ($postback, $claim, $attempt, $delivered): void {
            $now = CarbonImmutable::now();
            TrafficPostback::query()->whereKey($postback->id)->where('attempts', $claim['attempts'])->where('status', TrafficPostbackStatus::Sending)->update([
                'status' => $delivered ? TrafficPostbackStatus::Delivered : TrafficPostbackStatus::Failed,
                'finished_at' => $now,
                'http_status' => $attempt->httpStatus,
                'latency_ms' => $attempt->latencyMs,
                'outcome' => $attempt->outcome,
                'response_excerpt' => $attempt->excerpt,
            ]);

            if (! $delivered) {
                TrafficPostbackAttempt::query()->create([
                    'postback_id' => $postback->id,
                    'attempt' => $claim['attempts'],
                    'attempted_at' => $now,
                    'http_status' => $attempt->httpStatus,
                    'latency_ms' => $attempt->latencyMs,
                    'outcome' => $attempt->outcome,
                    'response_excerpt' => $attempt->excerpt,
                ]);
            }
        });

        return $attempt;
    }
}
