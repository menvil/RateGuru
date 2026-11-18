<?php

namespace App\Support\Traffic\Blocking;

use App\Enums\TrafficBlockVerdict;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Reports\ReportFigures;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Whether a site or zone of an ad network is worth blocking, by its window
 * (the rule's last 30 days, or the report's period) and the thresholds of its
 * source (BlockRule):
 *
 * - bots, when enough clicks came and fewer opened the page than the rule's
 *   share and than a share of its source's own average — pop traffic opens
 *   far less often than a banner, so the same share would call a whole pop
 *   source bots — or fewer than the rule's floor, whatever the average: a
 *   source that is nearly all bots has a tiny average, and one with a single
 *   site can't fall below a share of itself, yet a site that rarely opens
 *   the page never pays back, bots or not;
 * - losing, when it has cost enough and its worth falls too far short of its
 *   spend — judged only once accounts are measured on the site, and only so
 *   many days after its first click in the window, so its accounts have time
 *   to come; until then such a site is waiting. Enough is so many times the
 *   target price of an account where its campaign or its source has one,
 *   else so much spent or so many clicks. A site clicked on the window's
 *   first day isn't new — it was there before the window opened — so it
 *   waits for no days, however many the rule asks;
 * - a weak start when, before it has spent or been clicked that much, too
 *   few who opened the page started the game compared with the source.
 *
 * Otherwise its state: early, profit or watch. Bots and a weak start wait for
 * neither the days nor the target.
 */
final class BlockJudge
{
    /**
     * @param  array<string, int|string>  $rule  every key of BlockRule::KEYS, as they apply to the site's source
     * @param  ?float  $sourceOpenRate  how often its source's clicks opened the page over the same window, 0–1
     * @param  ?string  $target  the target price of an account its money waits for (BlockRule::target())
     * @param  ?CarbonInterface  $firstClickAt  its first click in the window
     * @param  ?CarbonInterface  $windowFrom  the start of the window's first day, UTC
     * @return array{verdict: TrafficBlockVerdict|null, state: string}
     */
    public static function judge(ReportFigures $figures, array $rule, ?float $sourceStartRate, ?float $sourceOpenRate, bool $worthKnown, ?string $target, ?CarbonInterface $firstClickAt, CarbonInterface $now, ?CarbonInterface $windowFrom = null): array
    {
        if (self::bots($figures, $rule, $sourceOpenRate)) {
            return ['verdict' => TrafficBlockVerdict::Bots, 'state' => 'bots'];
        }

        $spend = TrafficMoney::of($figures->spend);
        // The money's first gate as it always stood: a weak start shows only below it.
        $minimums = $spend->isGreaterThanOrEqualTo(TrafficMoney::of($rule['block_min_spend'])) || $figures->visits >= (int) $rule['block_min_clicks'];
        $needed = self::spendNeeded($rule, $target);

        if (($needed === null ? $minimums : $spend->isGreaterThanOrEqualTo($needed)) && $worthKnown) {
            $roi = $figures->roi();

            if ($spend->isPositive() && $roi !== null && $roi < (int) $rule['block_roi_below']) {
                $from = self::judgedFrom($rule, $firstClickAt, $windowFrom);

                return $from === null || $from->lessThanOrEqualTo($now)
                    ? ['verdict' => TrafficBlockVerdict::Losing, 'state' => 'losing']
                    : ['verdict' => null, 'state' => 'waiting'];
            }

            return ['verdict' => null, 'state' => $figures->value()->isGreaterThanOrEqualTo($spend) ? 'profit' : 'watch'];
        }

        if (! $minimums && $sourceStartRate !== null && $figures->visits >= (int) $rule['block_start_clicks'] && $figures->opened > 0
            && $figures->started / $figures->opened < $sourceStartRate * (int) $rule['block_start_percent'] / 100) {
            return ['verdict' => TrafficBlockVerdict::WeakStart, 'state' => 'weak_start'];
        }

        return ['verdict' => null, 'state' => 'early'];
    }

    /**
     * The share of its clicks, in percent, below which a site's opening the
     * page calls it bots: the rule's share, or a share of its source's own
     * average where that is lower — the rule's alone while the source has no
     * clicks to average — and never below the rule's floor, which 0 turns off.
     *
     * @param  array<string, int|string>  $rule
     */
    public static function openBelow(array $rule, ?float $sourceOpenRate): float
    {
        return max((float) (int) $rule['block_open_floor'], self::lowerShare($rule, $sourceOpenRate));
    }

    /**
     * Whether the share a site is held to is a share of its source's average
     * rather than the rule's own share or its floor.
     *
     * @param  array<string, int|string>  $rule
     */
    public static function openBelowFromAverage(array $rule, ?float $sourceOpenRate): bool
    {
        return ! self::openBelowFromFloor($rule, $sourceOpenRate) && self::lowerShare($rule, $sourceOpenRate) < (float) (int) $rule['block_open_below'];
    }

    /**
     * Whether the share a site is held to is the rule's floor: the lower of
     * the rule's share and the share of its source's average falls below it.
     *
     * @param  array<string, int|string>  $rule
     */
    public static function openBelowFromFloor(array $rule, ?float $sourceOpenRate): bool
    {
        return (float) (int) $rule['block_open_floor'] > self::lowerShare($rule, $sourceOpenRate);
    }

    /**
     * What a site must have spent before its money is judged, where it has a
     * target price of an account: so many times the target. Null without one,
     * when so much spent or so many clicks will do.
     *
     * @param  array<string, int|string>  $rule
     */
    public static function spendNeeded(array $rule, ?string $target): ?BigDecimal
    {
        if ($target === null || ! TrafficMoney::of($target)->isPositive()) {
            return null;
        }

        return TrafficMoney::of($target)->multipliedBy(TrafficMoney::of($rule['block_target_multiple']));
    }

    /**
     * From when a site may be called losing: so many days after its first
     * click in the window. Null — no wait — when its first click isn't known,
     * or came on the window's first day: the window can't see further back,
     * so such a site was likely clicked before it opened, and counting the
     * days from there would leave a long-losing site waiting for ever once
     * the rule waits about as long as the window.
     *
     * @param  array<string, int|string>  $rule
     */
    public static function judgedFrom(array $rule, ?CarbonInterface $firstClickAt, ?CarbonInterface $windowFrom = null): ?CarbonImmutable
    {
        if ($firstClickAt === null || ($windowFrom !== null && $firstClickAt->lessThan(CarbonImmutable::instance($windowFrom)->addDay()))) {
            return null;
        }

        return CarbonImmutable::instance($firstClickAt)->addDays((int) $rule['block_grace_days']);
    }

    /**
     * The lower of the rule's share and the share of its source's average —
     * the rule's alone while the source has no clicks to average — before
     * the floor lifts it.
     *
     * @param  array<string, int|string>  $rule
     */
    private static function lowerShare(array $rule, ?float $sourceOpenRate): float
    {
        $share = (float) (int) $rule['block_open_below'];

        return $sourceOpenRate === null ? $share : min($share, round($sourceOpenRate * (int) $rule['block_open_below_average'], 6));
    }

    /** @param  array<string, int|string>  $rule */
    private static function bots(ReportFigures $figures, array $rule, ?float $sourceOpenRate): bool
    {
        return $figures->visits > 0 && $figures->visits >= (int) $rule['block_bot_clicks']
            && round($figures->opened * 100 / $figures->visits, 6) < self::openBelow($rule, $sourceOpenRate);
    }
}
