<?php

namespace App\Support\Traffic\Spend;

use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Shares a day's spend figures out over the day's visits, so the visits add
 * up to the figures exactly. The narrowest figure goes first — a zone's,
 * then a site's, a campaign's, the whole source's — over the visits it
 * matches that no narrower figure has taken; each visit by the price its
 * click carried when every one of them carried one, else equally; amounts
 * cut to 8 decimals, what is left over going to the newest visit. A visit
 * no figure reaches costs nothing; a figure no visit reaches is spend
 * without visits.
 */
final class SpendAllocator
{
    /**
     * @param  list<array{id: int, campaign_id: int|null, site_ref: string, zone_ref: string, amount: string}>  $facts
     * @param  list<array{id: int, campaign_id: int|null, site_ref: string|null, zone_ref: string|null, cost: string|null}>  $visits
     * @return array{visits: array<int, string>, facts: array<int, array{allocated: string, matched: int}>}
     */
    public static function plan(array $facts, array $visits): array
    {
        usort($facts, fn (array $a, array $b): int => [self::breadth($a), $a['id']] <=> [self::breadth($b), $b['id']]);
        $spend = array_fill_keys(array_column($visits, 'id'), TrafficMoney::stored(0));
        $taken = [];
        $result = [];

        foreach ($facts as $fact) {
            $matched = array_values(array_filter($visits, fn (array $visit): bool => ! isset($taken[$visit['id']])
                && ($fact['campaign_id'] === null || $fact['campaign_id'] === $visit['campaign_id'])
                && ($fact['site_ref'] === '' || $fact['site_ref'] === (string) $visit['site_ref'])
                && ($fact['zone_ref'] === '' || $fact['zone_ref'] === (string) $visit['zone_ref'])));

            if ($matched === []) {
                $result[$fact['id']] = ['allocated' => TrafficMoney::stored(0), 'matched' => 0];

                continue;
            }

            $byCost = array_reduce($matched, fn (bool $all, array $visit): bool => $all && $visit['cost'] !== null && TrafficMoney::of($visit['cost'])->isPositive(), true);
            $weights = array_map(fn (array $visit): BigDecimal => $byCost ? TrafficMoney::of($visit['cost']) : BigDecimal::one(), $matched);
            $total = array_reduce($weights, fn (BigDecimal $sum, BigDecimal $weight): BigDecimal => $sum->plus($weight), BigDecimal::zero());
            $amount = TrafficMoney::of($fact['amount']);
            $given = BigDecimal::zero();
            $newest = max(array_column($matched, 'id'));

            foreach ($matched as $index => $visit) {
                $share = $amount->multipliedBy($weights[$index])->dividedBy($total, TrafficMoney::SCALE, RoundingMode::Down);
                $spend[$visit['id']] = (string) $share;
                $given = $given->plus($share);
                $taken[$visit['id']] = true;
            }

            $spend[$newest] = (string) TrafficMoney::of($spend[$newest])->plus($amount->minus($given))->toScale(TrafficMoney::SCALE);
            $result[$fact['id']] = ['allocated' => TrafficMoney::stored($amount), 'matched' => count($matched)];
        }

        return ['visits' => $spend, 'facts' => $result];
    }

    /** 0 for a zone's figure, 1 a site's, 2 a campaign's, 3 the whole source's. */
    private static function breadth(array $fact): int
    {
        return match (true) {
            $fact['zone_ref'] !== '' => 0,
            $fact['site_ref'] !== '' => 1,
            $fact['campaign_id'] !== null => 2,
            default => 3,
        };
    }
}
