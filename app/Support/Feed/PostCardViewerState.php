<?php

namespace App\Support\Feed;

use App\Enums\VoteType;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;
use App\Support\SavedPosts\SavedPostState;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a page of post cards shows the viewer about each post — their vote,
 * whether they saved it, whether they may vote — read for the whole page in
 * two queries, so no card's voting or save button asks again for its own.
 */
final class PostCardViewerState
{
    public function __construct(private readonly SavedPostState $savedPosts) {}

    /**
     * @param  Collection<int, Post>  $posts
     * @return array<int, array{vote: ?string, saved: bool, canVote: bool}>
     */
    public function forPosts(Collection $posts, ?User $viewer): array
    {
        $ids = $posts->modelKeys();

        if ($ids === []) {
            return [];
        }

        $votes = $viewer === null ? [] : PostVote::query()
            ->where('user_id', $viewer->id)
            ->whereIn('post_id', $ids)
            ->pluck('type', 'post_id')
            ->map(fn (VoteType|string $type): string => $type instanceof VoteType ? $type->value : $type)
            ->all();

        $saved = $this->savedPosts->forUserAndPosts($viewer, $posts);

        return $posts->mapWithKeys(fn (Post $post): array => [(int) $post->id => [
            'vote' => $votes[$post->id] ?? null,
            'saved' => $saved->isSaved($post),
            'canVote' => $viewer === null || $viewer->can('vote', $post),
        ]])->all();
    }
}
