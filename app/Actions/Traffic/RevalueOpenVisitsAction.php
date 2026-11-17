<?php

namespace App\Actions\Traffic;

use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Services\Traffic\TrafficVisitWorth;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Worth\VisitValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Brings the worth of the visits still open up to date after the chances
 * were worked out anew, or as they age: new visitors' visits without an
 * account that may still bring one, and accounts whose bonuses' windows are
 * open. In batches by key, each visit locked while it is worked out; a value
 * is written only when it moved, and a visit that closed goes to 0.
 */
final class RevalueOpenVisitsAction
{
    private const int BATCH = 500;

    private const string NOTICEABLE = '0.00005';

    public function __construct(private readonly TrafficVisitWorth $worth) {}

    public function handle(CarbonImmutable $now): int
    {
        $longestWindow = (int) (TrafficSource::query()->max('attribution_days') ?? 7);
        $since = $now->subDays($longestWindow + VisitValue::PAID_CLOSES_AFTER_WINDOW_DAYS + 1);
        $accountsSince = $now->subDays(VisitValue::POST_BONUS_DAYS + 1);
        $changed = 0;
        $after = 0;

        do {
            $ids = TrafficVisit::query()
                ->where('id', '>', $after)
                ->whereNotNull('opened_at')
                ->where('known', false)
                ->where(fn ($open) => $open
                    ->where(fn ($young) => $young->whereNull('account_at')->where('arrived_at', '>=', $since))
                    ->orWhere(fn ($account) => $account->whereNotNull('account_at')->where('signup_at', '>=', $accountsSince)))
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $after = (int) end($ids);

            DB::transaction(function () use ($ids, $now, &$changed): void {
                foreach (TrafficVisit::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $visit) {
                    $values = $this->worth->values($visit, $now);

                    if ($this->moved($visit->value_real, $values['real']) || $this->moved($visit->value_expected, $values['expected'])) {
                        TrafficVisit::query()->whereKey($visit->id)->update(['value_real' => $values['real'], 'value_expected' => $values['expected']]);
                        $changed++;
                    }
                }
            });
        } while (count($ids) === self::BATCH);

        return $changed;
    }

    private function moved(mixed $stored, string $worked): bool
    {
        return TrafficMoney::of($stored)->minus(TrafficMoney::of($worked))->abs()->isGreaterThan(TrafficMoney::of(self::NOTICEABLE));
    }
}
