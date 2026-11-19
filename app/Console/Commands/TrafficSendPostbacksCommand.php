<?php

namespace App\Console\Commands;

use App\Enums\TrafficPostbackOutcome;
use App\Enums\TrafficPostbackStatus;
use App\Enums\TrafficSourceStatus;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackAttempt;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisitDetail;
use App\Services\Traffic\Postbacks\PostbackAttempt;
use App\Services\Traffic\Postbacks\PostbackDeliverer;
use App\Support\Observability\DomainLogger;
use App\Support\Traffic\Postbacks\PostbackSchedule;
use App\Support\Traffic\Postbacks\PostbackUrl;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends the postbacks waiting in the queue — the table itself, not the job
 * queue its one worker shares with media and translations
 * (docs/architecture/traffic.md, "Postbacks"). Every minute, for up to 45
 * seconds: a batch is claimed under a lock, sent outside any transaction,
 * and each answer written back only if the claim still stands. A claim not
 * written back within two minutes is taken up again: delivery is at least
 * once. A source whose network failed five times running in this run is
 * left for the next.
 */
final class TrafficSendPostbacksCommand extends Command
{
    protected $signature = 'traffic:send-postbacks';

    protected $description = 'Send the postbacks waiting to be sent to ad networks.';

    // One pool at a time: a source that keeps failing is rested between pools, its rows not yet claimed left waiting without spending an attempt.
    private const int BATCH = PostbackDeliverer::CONCURRENCY;

    private const int SECONDS = 45;

    private const int SOURCE_FAILURES = 5;

    public function handle(PostbackDeliverer $deliverer, DomainLogger $log): int
    {
        if (! TrafficSetting::current()->postbacks_enabled) {
            return self::SUCCESS;
        }

        $deadline = microtime(true) + self::SECONDS;
        $failures = [];
        $sent = 0;

        TrafficPostback::query()->where('status', TrafficPostbackStatus::Sending)->where('run_after', '<=', now())->update(['status' => TrafficPostbackStatus::Pending]);

        while (microtime(true) < $deadline) {
            $resting = array_keys(array_filter($failures, fn (int $count): bool => $count >= self::SOURCE_FAILURES));
            $claimed = $this->claim($resting);

            // Nothing due: the queue is done for this run. A batch of only expired or cancelled rows: on to the next.
            if ($claimed === null) {
                break;
            }

            if ($claimed === []) {
                continue;
            }

            $results = $deliverer->send(array_map(fn (array $claim): array => ['url' => $claim['url'], 'rules' => $claim['rules']], $claimed));

            foreach ($claimed as $id => $claim) {
                $attempt = $results[$id];

                // One answer that can't be kept must not leave the rest of the batch marked as being sent.
                try {
                    $this->record($id, $claim['attempts'], $claim['expires_at'], $attempt);
                } catch (Throwable $exception) {
                    report($exception);

                    continue;
                }

                $network = in_array($attempt->outcome, [TrafficPostbackOutcome::Timeout, TrafficPostbackOutcome::ConnectionError], true);
                $failures[$claim['source_id']] = $network ? ($failures[$claim['source_id']] ?? 0) + 1 : 0;
                $log->info('traffic.postback.attempt', ['postback_id' => $id, 'outcome' => $attempt->outcome->value, 'http_status' => $attempt->httpStatus]);
                $sent++;
            }
        }

        $this->info("Sent {$sent} postbacks.");

        return self::SUCCESS;
    }

    /**
     * The next batch, claimed: each row locked and checked again, those past
     * their time expired, those whose source, rule or click is gone cancelled,
     * the rest marked as being sent.
     *
     * @param  list<int>  $resting  sources left for the next run
     * @return array<int, array{url: string, rules: array{rules: list<array{outcome: string, match: string, text: string}>, otherwise: string}|null, attempts: int, expires_at: CarbonImmutable|null, source_id: int}>|null null when no postback was due
     */
    private function claim(array $resting): ?array
    {
        return DB::transaction(function () use ($resting): ?array {
            $now = CarbonImmutable::now();
            $rows = TrafficPostback::query()
                ->where('status', TrafficPostbackStatus::Pending)
                ->where('run_after', '<=', $now)
                ->when($resting !== [], fn ($query) => $query->whereNotIn('source_id', $resting))
                ->orderBy('run_after')
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id')
                ->all();

            if ($rows === []) {
                return null;
            }

            $claimed = [];
            $postbacks = TrafficPostback::query()->whereIn('id', $rows)->orderBy('id')->lockForUpdate()->with(['rule', 'conversion'])->get();
            $sources = TrafficSource::query()->whereIn('id', $postbacks->pluck('source_id')->unique())->get()->keyBy('id');
            $clickIds = TrafficVisitDetail::query()->whereIn('visit_id', $postbacks->pluck('conversion.visit_id')->filter()->unique())->pluck('click_id', 'visit_id');

            foreach ($postbacks as $postback) {
                if ($postback->status !== TrafficPostbackStatus::Pending || $postback->run_after->greaterThan($now)) {
                    continue;
                }

                $source = $sources->get($postback->source_id);
                $clickId = (string) ($clickIds[$postback->conversion?->visit_id] ?? '');

                if ($postback->expires_at !== null && $now->greaterThan($postback->expires_at)) {
                    $postback->forceFill(['status' => TrafficPostbackStatus::Expired, 'finished_at' => $now])->save();

                    continue;
                }

                if ($source?->status !== TrafficSourceStatus::Active || $postback->rule === null || ! $postback->rule->enabled || $clickId === '') {
                    $postback->forceFill(['status' => TrafficPostbackStatus::Cancelled, 'finished_at' => $now])->save();

                    continue;
                }

                $postback->forceFill([
                    'status' => TrafficPostbackStatus::Sending,
                    'attempts' => $postback->attempts + 1,
                    'claimed_at' => $now,
                    'run_after' => $now->addSeconds(PostbackSchedule::CLAIM_SECONDS),
                ])->save();

                $claimed[(int) $postback->id] = [
                    'url' => PostbackUrl::withClickId($postback->url, $clickId),
                    'rules' => $source->response_rules,
                    'attempts' => $postback->attempts,
                    'expires_at' => $postback->expires_at,
                    'source_id' => (int) $postback->source_id,
                ];
            }

            return $claimed;
        });
    }

    /** The answer written back, if the claim still stands, with what follows it. */
    private function record(int $id, int $attempts, ?CarbonImmutable $expiresAt, PostbackAttempt $attempt): void
    {
        DB::transaction(function () use ($id, $attempts, $expiresAt, $attempt): void {
            $now = CarbonImmutable::now();
            $next = PostbackSchedule::after($attempt->outcome, $attempts, $expiresAt, $now);
            $updated = TrafficPostback::query()
                ->whereKey($id)
                ->where('status', TrafficPostbackStatus::Sending)
                ->where('attempts', $attempts)
                ->update([
                    'status' => $next['status'],
                    'run_after' => $next['run_after'] ?? $now,
                    'finished_at' => $next['status'] === TrafficPostbackStatus::Pending ? null : $now,
                    'http_status' => $attempt->httpStatus,
                    'latency_ms' => $attempt->latencyMs,
                    'outcome' => $attempt->outcome,
                    'response_excerpt' => $attempt->excerpt,
                ]);

            if ($updated === 1 && ! in_array($attempt->outcome, [TrafficPostbackOutcome::Ok, TrafficPostbackOutcome::Duplicate], true)) {
                TrafficPostbackAttempt::query()->create([
                    'postback_id' => $id,
                    'attempt' => $attempts,
                    'attempted_at' => $now,
                    'http_status' => $attempt->httpStatus,
                    'latency_ms' => $attempt->latencyMs,
                    'outcome' => $attempt->outcome,
                    'response_excerpt' => $attempt->excerpt,
                ]);
            }
        });
    }
}
