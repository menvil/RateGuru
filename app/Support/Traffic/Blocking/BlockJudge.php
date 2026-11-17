<?php

namespace App\Support\Traffic\Blocking;

use App\Enums\TrafficBlockVerdict;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Reports\ReportFigures;

/**
 * Whether a site or zone of an ad network is worth blocking, by its last 30
 * days: bots when enough clicks came and too few opened the page; losing
 * when it has cost enough (in money or clicks) and its worth falls too far
 * short of its spend — judged only once accounts are measured on the site;
 * a weak start when, before that, too few who opened the page started the
 * game compared with the source. Otherwise its state: early, profit or
 * watch.
 */
final class BlockJudge
{
    /**
     * @param  array{block_min_spend: string, block_min_clicks: int, block_roi_below: int, block_open_below: int, block_bot_clicks: int, block_start_clicks: int, block_start_percent: int}  $rule
     * @return array{verdict: TrafficBlockVerdict|null, state: string}
     */
    public static function judge(ReportFigures $figures, ?float $sourceStartRate, bool $worthKnown, array $rule): array
    {
        if ($figures->visits >= $rule['block_bot_clicks'] && $figures->opened * 100 < $rule['block_open_below'] * $figures->visits) {
            return ['verdict' => TrafficBlockVerdict::Bots, 'state' => 'bots'];
        }

        $spend = TrafficMoney::of($figures->spend);
        $enough = $spend->isGreaterThanOrEqualTo(TrafficMoney::of($rule['block_min_spend'])) || $figures->visits >= $rule['block_min_clicks'];

        if ($enough) {
            $roi = $figures->roi();

            if ($worthKnown && $spend->isPositive() && $roi !== null && $roi < $rule['block_roi_below']) {
                return ['verdict' => TrafficBlockVerdict::Losing, 'state' => 'losing'];
            }

            if (! $worthKnown) {
                return ['verdict' => null, 'state' => 'early'];
            }

            return ['verdict' => null, 'state' => $figures->value()->isGreaterThanOrEqualTo($spend) ? 'profit' : 'watch'];
        }

        if ($sourceStartRate !== null && $figures->visits >= $rule['block_start_clicks'] && $figures->opened > 0
            && $figures->started / $figures->opened < $sourceStartRate * $rule['block_start_percent'] / 100) {
            return ['verdict' => TrafficBlockVerdict::WeakStart, 'state' => 'weak_start'];
        }

        return ['verdict' => null, 'state' => 'early'];
    }
}
