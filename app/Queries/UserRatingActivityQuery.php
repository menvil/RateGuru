<?php

namespace App\Queries;

use App\Enums\PostStatus;
use App\Models\RatingVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class UserRatingActivityQuery
{
    /** @return Collection<int, RatingVote> */
    public function forProfile(User $profileUser, ?User $viewer, int $limit = 30): Collection
    {
        $isOwner = $viewer !== null && $viewer->id === $profileUser->id;
        $isPublic = $profileUser->rating_activity_visibility === 'public';

        if (! $isOwner && ! $isPublic) {
            return new Collection();
        }

        return RatingVote::query()
            ->where('user_id', $profileUser->id)
            ->whereHas('post', fn ($q) => $q->where('status', PostStatus::Published))
            ->with(['post', 'group', 'option'])
            ->latest()
            ->limit($limit)
            ->get();
    }
}
