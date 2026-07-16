<?php

namespace App\Queries\Feed;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Database\LikePattern;
use Illuminate\Database\Eloquent\Collection;

final class MatchedUsersQuery
{
    /** @return Collection<int, User> */
    public function search(string $search): Collection
    {
        $pattern = LikePattern::containing($search);

        return User::query()
            ->where('status', UserStatus::Active)
            ->where(function ($query) use ($pattern): void {
                $query
                    ->whereRaw("username LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("display_name LIKE ? ESCAPE '!'", [$pattern]);
            })
            ->orderBy('username')
            ->limit(5)
            ->get();
    }
}
