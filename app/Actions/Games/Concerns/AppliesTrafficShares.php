<?php

namespace App\Actions\Games\Concerns;

use App\Enums\GameAudience;
use App\Models\Game;
use App\Models\GameAssignment;
use App\Models\GameTrafficPeriod;
use App\Models\User;
use App\Support\Games\Traffic\TrafficScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * How an audience's shares take effect, for publishing with traffic and for
 * Traffic itself, on a game already locked — everyone's shares, or a split's
 * for a source or campaign: the scope's open period is updated in place while
 * nobody has been assigned in it yet; otherwise it ends now and a new one
 * starts with the new shares. No shares at all is an explicit period of no
 * traffic.
 */
trait AppliesTrafficShares
{
    /**
     * @param  array<int, int>  $shares  version id => share
     * @return bool whether a new period started
     */
    private function applyShares(Game $game, GameAudience $audience, array $shares, User $admin, ?TrafficScope $scope = null): bool
    {
        $scope ??= TrafficScope::everyone();
        $current = GameTrafficPeriod::query()
            ->where('game_id', $game->id)
            ->where('audience', $audience)
            ->whereNull('ended_at')
            ->when($scope->sourceId === null, fn (Builder $query) => $query->whereNull('source_id'), fn (Builder $query) => $query->where('source_id', $scope->sourceId))
            ->when($scope->campaignId === null, fn (Builder $query) => $query->whereNull('campaign_id'), fn (Builder $query) => $query->where('campaign_id', $scope->campaignId))
            ->lockForUpdate()
            ->latest('id')
            ->first();

        ksort($shares);
        $pivot = array_map(fn (int $share): array => ['share_percent' => $share], $shares);
        $existing = $current?->shares() ?? [];
        ksort($existing);

        if ($current !== null && $existing === $shares) {
            return false;
        }

        if ($current !== null && ! GameAssignment::query()->where('period_id', $current->id)->exists()) {
            $current->versions()->sync($pivot);

            return false;
        }

        $current?->forceFill(['ended_at' => now(), 'open_scope' => null])->save();
        // An older period of the scope somehow left open is never read (the latest is): it gives up the name.
        GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', $audience)->where('open_scope', $scope->key())->update(['open_scope' => null]);

        $period = new GameTrafficPeriod;
        $period->forceFill([
            'game_id' => $game->id,
            'audience' => $audience,
            'source_id' => $scope->sourceId,
            'campaign_id' => $scope->campaignId,
            'open_scope' => $scope->key(),
            'started_at' => now(),
            'started_by_user_id' => $admin->id,
        ])->save();
        $period->versions()->sync($pivot);

        return true;
    }
}
