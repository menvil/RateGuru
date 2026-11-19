<?php

namespace App\Actions\Traffic;

use App\Models\TrafficVisit;
use App\Services\Traffic\TrafficVisitWorth;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Presets\PresetSchema;
use App\Support\Traffic\Worth\VisitValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Brings the worth of the visits still open up to date after the chances
 * were worked out anew, or as they age: new visitors' visits without an
 * account that may still bring one, and accounts whose bonuses' windows are
 * open — of the visits the tracker counts; a visit to the rest of the site
 * is worth nothing for good (TrafficVisit::scopeTracked()). In batches by key, each visit locked while it is worked out; a value
 * is written only when it moved, and a visit that closed goes to 0.
 */
final class RevalueOpenVisitsAction
{
    private const int BATCH = 500;

    private const string NOTICEABLE = '0.00005';

    /**
     * How long a visit is still swept after it closed: a night or a few the
     * scheduler missed still bring a closed visit to 0, rather than leaving it
     * outside every later scan with the worth it last had.
     */
    private const int SWEPT_AFTER_CLOSE_DAYS = 7;

    public function __construct(private readonly TrafficVisitWorth $worth) {}

    public function handle(CarbonImmutable $now): int
    {
        // A visit is open until its own close — a paid click a week after the window it was given, an unpaid visit two weeks after it came —
        // whatever the sources' windows are now; the longest window there can be bounds the scan.
        $coarse = $now->subDays(max(max(PresetSchema::ATTRIBUTION_DAYS) + VisitValue::PAID_CLOSES_AFTER_WINDOW_DAYS, VisitValue::UNPAID_CLOSES_AFTER_DAYS) + self::SWEPT_AFTER_CLOSE_DAYS);
        $paidHeldSince = $now->subDays(VisitValue::PAID_CLOSES_AFTER_WINDOW_DAYS + self::SWEPT_AFTER_CLOSE_DAYS);
        $unpaidSince = $now->subDays(VisitValue::UNPAID_CLOSES_AFTER_DAYS + self::SWEPT_AFTER_CLOSE_DAYS);
        $accountsSince = $now->subDays(VisitValue::POST_BONUS_DAYS + self::SWEPT_AFTER_CLOSE_DAYS);
        $changed = 0;
        $after = 0;

        do {
            $ids = TrafficVisit::query()
                ->tracked()
                ->where('id', '>', $after)
                // The page ran, or the visitor signed up all the same: a click that reached nothing is worth nothing and stays out.
                ->where(fn ($ran) => $ran->whereNotNull('opened_at')->orWhereNotNull('signup_at'))
                ->where('known', false)
                ->where(fn ($open) => $open
                    ->where(fn ($young) => $young->whereNull('account_at')->where('arrived_at', '>=', $coarse)->where(fn ($closing) => $closing
                        ->where(fn ($paid) => $paid->whereNotNull('holds_until')->where('holds_until', '>=', $paidHeldSince))
                        ->orWhere(fn ($unpaid) => $unpaid->whereNull('holds_until')->where('arrived_at', '>=', $unpaidSince))))
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
        $stored = TrafficMoney::of($stored);
        $worked = TrafficMoney::of($worked);

        // Nothing is nothing: a closed visit's last crumb goes, however small.
        if ($worked->isZero() && ! $stored->isZero()) {
            return true;
        }

        return $stored->minus($worked)->abs()->isGreaterThan(TrafficMoney::of(self::NOTICEABLE));
    }
}
