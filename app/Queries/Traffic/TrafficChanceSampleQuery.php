<?php

namespace App\Queries\Traffic;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Models\TrafficVisit;
use App\Support\Traffic\Worth\ChanceCalculator;
use App\Support\Traffic\Worth\ConfirmationCurve;
use App\Support\Traffic\Worth\VisitValue;
use Carbon\CarbonImmutable;

/**
 * The visits the chances are worked out from, in one pass over the last 120
 * days (docs/architecture/traffic.md, "Worth"): new visitors' visits whose
 * page ran in a browser and that have matured — a paid click 7 days after its
 * window, by when an unconfirmed account is gone; an unpaid visit after 14
 * days — counted by game, source, channel, device, country and rounds
 * answered: how many reached each step and how many of those became a
 * confirmed account, how many accounts posted and came back, and how soon
 * accounts were confirmed. Conditional sums with every value bound.
 */
final class TrafficChanceSampleQuery implements RawSqlPersistenceBoundary
{
    public const int DAYS = 120;

    /** @return list<array<string, mixed>> */
    public function rows(CarbonImmutable $now): array
    {
        $paidMatured = $now->subDays(VisitValue::PAID_CLOSES_AFTER_WINDOW_DAYS);
        $unpaidMatured = $now->subDays(VisitValue::UNPAID_CLOSES_AFTER_DAYS);
        $selects = ['COUNT(*) AS visits', 'SUM(CASE WHEN account_at IS NOT NULL THEN 1 ELSE 0 END) AS accounts'];
        $bindings = [];

        foreach (ChanceCalculator::MILESTONES as $step) {
            $column = $step.'_at';
            $selects[] = "SUM(CASE WHEN {$column} IS NOT NULL THEN 1 ELSE 0 END) AS {$step}_n";
            $selects[] = "SUM(CASE WHEN {$column} IS NOT NULL AND account_at IS NOT NULL THEN 1 ELSE 0 END) AS {$step}_x";
        }

        $selects[] = 'SUM(CASE WHEN account_at IS NOT NULL AND signup_at <= ? THEN 1 ELSE 0 END) AS post_n';
        $selects[] = 'SUM(CASE WHEN account_at IS NOT NULL AND signup_at <= ? AND post_published_at IS NOT NULL THEN 1 ELSE 0 END) AS post_x';
        $selects[] = 'SUM(CASE WHEN account_at IS NOT NULL AND signup_at <= ? THEN 1 ELSE 0 END) AS returned_n';
        $selects[] = 'SUM(CASE WHEN account_at IS NOT NULL AND signup_at <= ? AND returned_at IS NOT NULL THEN 1 ELSE 0 END) AS returned_x';
        array_push($bindings, $now->subDays(VisitValue::POST_BONUS_DAYS), $now->subDays(VisitValue::POST_BONUS_DAYS), $now->subDays(VisitValue::RETURN_BONUS_DAYS), $now->subDays(VisitValue::RETURN_BONUS_DAYS));

        foreach (ConfirmationCurve::GRID as $index => $seconds) {
            $selects[] = "SUM(CASE WHEN account_at IS NOT NULL AND account_after_seconds <= ? THEN 1 ELSE 0 END) AS curve_{$index}";
            $bindings[] = $seconds;
        }

        return TrafficVisit::query()
            ->where('arrived_at', '>=', $now->subDays(self::DAYS))
            ->whereNotNull('opened_at')
            ->where('known', false)
            ->where(fn ($matured) => $matured
                ->where(fn ($paid) => $paid->whereNotNull('holds_until')->where('holds_until', '<=', $paidMatured))
                ->orWhere(fn ($unpaid) => $unpaid->whereNull('holds_until')->where('arrived_at', '<=', $unpaidMatured)))
            ->groupBy('game_id', 'source_id', 'channel', 'device', 'country', 'rounds_answered')
            ->select('game_id', 'source_id', 'channel', 'device', 'country', 'rounds_answered')
            ->selectRaw(implode(', ', $selects), $bindings)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }
}
