<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficSpendMode;
use App\Enums\TrafficSpendOrigin;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Support\Traffic\Spend\FixedPriceSchedule;
use Carbon\CarbonImmutable;

/**
 * Keeps a placement's fixed price as a cost for each of its days, from its
 * first day to today (FixedPriceSchedule): a change to the price or its
 * dates redoes every day, and a day no longer in the schedule loses its
 * figure.
 */
final class MaterializeFixedSpendAction
{
    public function __construct(private readonly ReplaceSpendSliceAction $slices) {}

    public function handle(TrafficSource $source, ?string $onlyFrom = null): void
    {
        $today = CarbonImmutable::now($source->reporting_timezone)->toDateString();
        $schedule = $source->spend_mode === TrafficSpendMode::Fixed && $source->fixed_amount !== null && $source->fixed_period !== null && trim((string) $source->fixed_from) !== ''
            ? FixedPriceSchedule::days((string) $source->fixed_amount, $source->fixed_period, (string) $source->fixed_from, $source->fixed_to, $today)
            : [];
        $stored = TrafficSpendDay::query()->where('source_id', $source->id)->where('origin', TrafficSpendOrigin::Fixed)->distinct()->pluck('network_day')->all();

        foreach (array_unique([...array_keys($schedule), ...$stored]) as $day) {
            if ($onlyFrom !== null && $day < $onlyFrom) {
                continue;
            }

            $amount = $schedule[$day] ?? null;
            $this->slices->handle((int) $source->id, $day, TrafficSpendOrigin::Fixed, $amount === null ? [] : [['campaign_id' => null, 'site_ref' => '', 'zone_ref' => '', 'amount' => $amount]]);
        }
    }
}
