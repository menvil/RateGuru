<?php

namespace App\Queries\Games;

use App\Models\GameContentSet;
use App\Models\GameFlow;

/**
 * A game's content set and flow loaded with everything their checks and a
 * snapshot read: every round's options with their posts and pictures (deleted
 * ones included, so a draft can say a post has left the site), the profiles,
 * the flow's steps.
 */
final class GameDraftQuery
{
    public function contentSet(int $gameId, int $contentSetId): ?GameContentSet
    {
        return GameContentSet::query()
            ->where('game_id', $gameId)
            ->with([
                'rounds.options.post.imageAsset.variants',
                'rounds.options.asset.variants',
                'profiles.shareAsset.variants',
            ])
            ->find($contentSetId);
    }

    public function flow(int $gameId, int $flowId): ?GameFlow
    {
        return GameFlow::query()
            ->where('game_id', $gameId)
            ->with('steps')
            ->find($flowId);
    }
}
