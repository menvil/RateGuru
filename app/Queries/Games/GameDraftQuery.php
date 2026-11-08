<?php

namespace App\Queries\Games;

use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\MediaAsset;
use App\Models\Post;
use Illuminate\Database\Eloquent\Collection;

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

    /**
     * The posts a draft names, deleted ones included, with their pictures, by id.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Post>
     */
    public function posts(array $ids): Collection
    {
        return Post::withTrashed()->with(['imageAsset.variants', 'user'])->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * The uploaded pictures a draft names, deleted ones included, by id.
     *
     * @param  list<int>  $ids
     * @return Collection<int, MediaAsset>
     */
    public function assets(array $ids): Collection
    {
        return MediaAsset::withTrashed()->with('variants')->whereIn('id', $ids)->get()->keyBy('id');
    }
}
