<?php

namespace App\Queries\Traffic;

use App\Enums\TrafficStepValueMode;
use App\Enums\TrafficValueBasis;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameVersion;
use App\Models\TrafficPriceSet;
use App\Models\TrafficStepChance;
use App\Support\Traffic\Worth\Slice;
use App\Support\Traffic\Worth\StepWorth;
use App\Support\Traffic\Worth\TrafficStep;
use App\Support\Traffic\Worth\WorthPrices;
use Brick\Math\BigDecimal;

/**
 * What each step of a game is worth in a slice of its traffic — a source or
 * a channel, a device, a country group, each of them or none — as the flow
 * editor and the game's Value tab show it: the worth in force, the worth the
 * traffic works out, the chance of an account, how many reached the step,
 * whether the slice stands on its own accounts or leans on the one above,
 * and the value set by hand; with what an account brings there.
 */
final class GameWorthQuery
{
    public function __construct(private readonly TrafficWorthQuery $worth) {}

    /** How many rounds a game's steps are laid out for: its longest version, else its first content set. */
    public function rounds(Game $game): int
    {
        $published = (int) GameVersion::query()->where('game_id', $game->id)->max('round_count');

        if ($published > 0) {
            return $published;
        }

        $set = GameContentSet::query()->where('game_id', $game->id)->whereNull('archived_at')->orderBy('position')->orderBy('id')->first();

        return max(1, $set === null ? 0 : $set->rounds()->count());
    }

    /** What a new visitor who opens the game is worth, every source, device and country; null before any working-out. */
    public function visitor(Game $game): ?BigDecimal
    {
        $run = $this->worth->lastRun();

        if ($run === null) {
            return null;
        }

        $slice = new Slice((int) $game->id);
        $set = TrafficPriceSet::current();
        $prices = new WorthPrices((string) $set->account_price, (string) $set->post_bonus, (string) $set->returned_bonus);

        return StepWorth::of($slice, 'opened', $prices, 100, false, $this->worth->manualValues((int) $game->id)['opened'] ?? null, $this->worth->table($run, $slice))['value'];
    }

    /**
     * @return array{
     *     steps: array<string, array{value: BigDecimal, worked: BigDecimal, basis: TrafficValueBasis, chance: float, thin: bool, reached: float|null, manual: array{value: string, mode: TrafficStepValueMode}|null}>,
     *     accounts: int,
     *     visitors: int,
     *     thin: bool,
     *     stands: string|null,
     *     worked: bool,
     *     percent: int,
     *     account: BigDecimal,
     *     postRate: float,
     *     returnRate: float,
     *     prices: WorthPrices,
     * }
     */
    public function slice(Game $game, int $rounds, ?int $sourceId = null, ?string $channel = null, ?string $device = null, ?string $group = null): array
    {
        $run = $this->worth->lastRun();
        $slice = new Slice((int) $game->id, $sourceId, $sourceId === null ? $channel : null, $device, $group);
        $table = $this->worth->table($run, $slice);
        $set = TrafficPriceSet::current();
        $prices = new WorthPrices((string) $set->account_price, (string) $set->post_bonus, (string) $set->returned_bonus);
        $percent = $group === null ? 100 : (int) (collect($set->country_groups)->firstWhere('key', $group)['percent'] ?? 100);
        $manual = $this->worth->manualValues((int) $game->id);
        $own = $run === null ? collect() : TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', $slice->key())->get(['step', 'visitors', 'slice_accounts', 'stands_on'])->keyBy('step');
        $opened = (int) ($own['opened']->visitors ?? 0);
        $steps = [];

        foreach (TrafficStep::all($rounds) as $step) {
            $now = StepWorth::of($slice, $step, $prices, $percent, false, $manual[$step] ?? null, $table);
            $worked = isset($manual[$step]) ? StepWorth::of($slice, $step, $prices, $percent, false, null, $table)['value'] : $now['value'];
            $steps[$step] = [
                'value' => $now['value'],
                'worked' => $worked,
                'basis' => $now['basis'],
                'chance' => $now['chance'],
                'thin' => $table->of($slice, $step)['thin'],
                'reached' => $opened > 0 && isset($own[$step]) ? min(1.0, (int) $own[$step]->visitors / $opened) : null,
                'manual' => in_array($step, ['account', 'login'], true) ? null : $manual[$step] ?? null,
            ];
        }

        $postRate = $table->of($slice, 'post_bonus')['chance'];
        $returnRate = $table->of($slice, 'returned_bonus')['chance'];
        $accounts = (int) ($own['opened']->slice_accounts ?? 0);
        $stands = (string) ($own['opened']->stands_on ?? '');

        return [
            'steps' => $steps,
            'accounts' => $accounts,
            'visitors' => $opened,
            'thin' => $accounts < 30,
            'stands' => $stands === '' || $stands === $slice->key() ? null : $stands,
            'worked' => ! $table->isEmpty(),
            'percent' => $percent,
            'account' => $prices->account($postRate, $returnRate, $percent),
            'postRate' => $postRate,
            'returnRate' => $returnRate,
            'prices' => $prices,
        ];
    }
}
