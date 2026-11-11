<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds a content set to a game: an empty one, or a copy of another set of the
 * game — its scoring, rounds, options, profiles and their translations, the
 * uploaded pictures shared rather than copied. Nothing published changes.
 */
final class CreateContentSetAction
{
    use LocksGameDraft;

    public const NAME_MAX = 80;

    /** @throws CannotManageGameException */
    public function handle(User $admin, Game $game, string $name, ?int $copyOfSetId = null): GameContentSet
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        $name = mb_substr(trim($name), 0, self::NAME_MAX);

        if ($name === '') {
            throw CannotManageGameException::because('Give the content set a name.');
        }

        return DB::transaction(function () use ($admin, $game, $name, $copyOfSetId): GameContentSet {
            $lockedAdmin = $this->lockAdministrator($admin);
            $locked = $this->lockGame((int) $game->id);
            $source = $copyOfSetId !== null ? $this->lockContentSet($locked, $copyOfSetId)->load(['rounds.options', 'profiles']) : null;

            $set = new GameContentSet;
            $set->forceFill([
                'game_id' => $locked->id,
                'name' => $name,
                'position' => (int) GameContentSet::query()->where('game_id', $locked->id)->max('position') + 1,
                'points_per_round' => $source !== null ? $source->points_per_round : 1,
                'crowd_min_votes' => $source !== null ? $source->crowd_min_votes : (int) config('games.crowd.min_votes'),
                'crowd_min_gap' => $source !== null ? $source->crowd_min_gap : (int) config('games.crowd.min_gap'),
            ])->save();

            if ($source !== null) {
                foreach ($source->rounds as $round) {
                    $copy = $round->replicate(['content_set_id']);
                    $copy->forceFill(['content_set_id' => $set->id])->save();

                    foreach ($round->options as $option) {
                        $optionCopy = $option->replicate(['round_id']);
                        $optionCopy->forceFill(['round_id' => $copy->id])->save();
                    }
                }

                foreach ($source->profiles as $profile) {
                    $profileCopy = $profile->replicate(['content_set_id']);
                    $profileCopy->forceFill(['content_set_id' => $set->id])->save();
                }
            }

            $this->touchGame($locked, $lockedAdmin);

            return $set;
        });
    }
}
