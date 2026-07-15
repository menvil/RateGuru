<?php

namespace App\Queries\Feed;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class MatchedUsersQuery
{
    /** @return Collection<int, User> */
    public function search(string $search): Collection
    {
        return User::query()
            ->where('status', UserStatus::Active)
            ->where(function ($query) use ($search): void {
                $query
                    ->where('username', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%");
            })
            ->orderBy('username')
            ->limit(5)
            ->get();
    }
}
