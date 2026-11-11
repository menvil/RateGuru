<?php

namespace App\Queries\Games;

use App\Data\Games\ContentSetDraft;
use App\Data\Games\GameFlowDraft;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Support\Games\Drafts\DraftContentSet;
use App\Support\Games\Flow\DraftFlow;
use Illuminate\Database\Eloquent\Collection;

/**
 * A game's content set and flow loaded with everything their checks and a
 * snapshot read: every round's options with their posts and pictures (deleted
 * ones included, so a draft can say a post has left the site), the profiles,
 * the flow's steps.
 */
final class GameDraftQuery
{
    private const CONTENT_SET_RELATIONS = [
        'rounds.options.post.imageAsset.variants',
        'rounds.options.asset.variants',
        'profiles.shareAsset.variants',
    ];

    public function contentSet(int $gameId, int $contentSetId): ?GameContentSet
    {
        return GameContentSet::query()
            ->where('game_id', $gameId)
            ->with(self::CONTENT_SET_RELATIONS)
            ->find($contentSetId);
    }

    /**
     * Every content set of a game not archived, in order, each loaded as
     * contentSet() loads one — in one go however many there are.
     *
     * @return Collection<int, GameContentSet>
     */
    public function contentSets(int $gameId): Collection
    {
        return GameContentSet::query()
            ->where('game_id', $gameId)
            ->whereNull('archived_at')
            ->with(self::CONTENT_SET_RELATIONS)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * A content set as an unsaved draft of it stands, for the live preview
     * alone (DraftContentSet), with the posts and pictures the draft names
     * loaded as contentSet() loads them. Never saved.
     */
    public function draftContentSet(int $gameId, int $contentSetId, ContentSetDraft $draft): ?GameContentSet
    {
        $saved = $this->contentSet($gameId, $contentSetId);

        if ($saved === null) {
            return null;
        }

        return DraftContentSet::of($saved, $draft, $this->posts(DraftContentSet::postIds($draft)), $this->assets(DraftContentSet::assetIds($draft)));
    }

    /** A flow as an unsaved draft of it stands, for the step preview alone (DraftFlow). Never saved. */
    public function draftFlow(int $gameId, int $flowId, GameFlowDraft $draft): ?GameFlow
    {
        $saved = $this->flow($gameId, $flowId);

        return $saved === null ? null : DraftFlow::of($saved, $draft);
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
