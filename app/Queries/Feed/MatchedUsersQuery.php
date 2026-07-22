<?php

namespace App\Queries\Feed;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Database\LikePattern;
use Illuminate\Database\Eloquent\Collection;

final class MatchedUsersQuery implements RawSqlPersistenceBoundary
{
    /** @return Collection<int, User> */
    public function search(string $search): Collection
    {
        $pattern = LikePattern::containing($search);

        return User::query()
            ->where('status', UserStatus::Active)
            ->where(function ($query) use ($pattern): void {
                $query
                    ->whereRaw("LOWER(username) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(display_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            })
            ->orderBy('username')
            ->limit(5)
            ->get();
    }
}
