<?php

namespace App\Queries\Games;

use App\Models\GameTrafficPeriod;
use Illuminate\Database\Eloquent\Collection;

/**
 * How each traffic period's scope reads beside its game and audience, where
 * the periods are listed — Growth → Experiments, Analytics → Experiments, a
 * game's Traffic and Analytics: a split's source and campaign (“ExoClick
 * tests”, “PopAds main · Campaign 881377”), and “Everyone else” for a
 * general period that ran alongside a split of the same game and audience —
 * its visitors were those no split covered. A general period that never ran
 * beside one reads as it always has, with nothing added. Read-only: one
 * query for the splits beside the general periods, and the sources and
 * campaigns of the splits among them loaded once.
 */
final class TrafficPeriodScopesQuery
{
    public const string EVERYONE_ELSE = 'Everyone else';

    /**
     * @param  iterable<GameTrafficPeriod>  $periods
     * @return array<int, string|null> by period id
     */
    public function labels(iterable $periods): array
    {
        $periods = new Collection(is_array($periods) ? $periods : iterator_to_array($periods, false));
        $splits = $periods->whereNotNull('source_id')->values();
        $general = $periods->whereNull('source_id')->values();
        $splits->loadMissing(['source:id,name', 'campaign:id,source_id,external_id,name']);

        $beside = $general->isEmpty() ? new Collection : GameTrafficPeriod::query()
            ->whereIn('game_id', $general->pluck('game_id')->unique()->values()->all())
            ->whereNotNull('source_id')
            ->get(['id', 'game_id', 'audience', 'started_at', 'ended_at']);
        $labels = [];

        foreach ($splits as $period) {
            $labels[(int) $period->id] = $period->scopeLabel();
        }

        foreach ($general as $period) {
            $labels[(int) $period->id] = $beside->contains(fn (GameTrafficPeriod $split): bool => self::overlap($period, $split)) ? self::EVERYONE_ELSE : null;
        }

        return $labels;
    }

    /** Whether a split of the period's game and audience was open at some moment the period was. */
    private static function overlap(GameTrafficPeriod $period, GameTrafficPeriod $split): bool
    {
        return (int) $split->game_id === (int) $period->game_id
            && $split->audience === $period->audience
            && ($period->ended_at === null || $split->started_at->lessThan($period->ended_at))
            && ($split->ended_at === null || $split->ended_at->greaterThan($period->started_at));
    }
}
