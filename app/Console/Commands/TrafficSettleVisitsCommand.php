<?php

namespace App\Console\Commands;

use App\Actions\Traffic\SettleTrafficVisitAction;
use App\Models\TrafficSetting;
use App\Models\TrafficVisit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Settles the paid visits that came to rest (docs/architecture/traffic.md,
 * "Settling"): every minute, for up to 45 seconds, each opened paid visit
 * not seen for the idle minutes, or whose click is the settle hours old,
 * at the moment it came to rest. A visit its account settled already is
 * left alone, and so is one older than twice the settle hours — a run
 * missed for that long tells nothing its network could still use. While
 * recording is off, nothing.
 */
final class TrafficSettleVisitsCommand extends Command
{
    protected $signature = 'traffic:settle-visits';

    protected $description = 'Settle the paid visits that came to rest, telling their networks what each was worth.';

    private const int SECONDS = 45;

    private const int BATCH = 500;

    public function handle(SettleTrafficVisitAction $settle): int
    {
        if (! TrafficSetting::current()->capture_enabled) {
            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $idle = (int) config('traffic.settle.idle_minutes', 30);
        $after = (int) config('traffic.settle.after_hours', 24);
        $deadline = microtime(true) + self::SECONDS;
        $last = 0;

        do {
            $visits = TrafficVisit::query()
                ->whereNull('settled_at')
                ->where('arrived_at', '>=', $now->subHours($after * 2))
                ->whereNotNull('source_id')
                ->whereNotNull('opened_at')
                ->where(fn ($rest) => $rest->where('last_seen_at', '<=', $now->subMinutes($idle))->orWhere('arrived_at', '<=', $now->subHours($after)))
                ->where('id', '>', $last)
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get(['id', 'arrived_at', 'last_seen_at']);

            foreach ($visits as $visit) {
                $last = (int) $visit->id;
                $at = min($now, $visit->last_seen_at->addMinutes($idle), $visit->arrived_at->addHours($after));

                try {
                    $settle->handle($last, $at);
                } catch (Throwable $exception) {
                    // One visit that can't be settled never holds up the rest.
                    report($exception);
                }

                if (microtime(true) >= $deadline) {
                    return self::SUCCESS;
                }
            }
        } while ($visits->count() === self::BATCH);

        return self::SUCCESS;
    }
}
