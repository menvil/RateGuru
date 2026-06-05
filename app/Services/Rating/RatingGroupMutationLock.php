<?php

namespace App\Services\Rating;

use App\Models\RatingGroup;
use Closure;
use Illuminate\Support\Facades\DB;

final class RatingGroupMutationLock
{
    public function run(int $groupId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($groupId, $callback): mixed {
            RatingGroup::query()
                ->whereKey($groupId)
                ->update(['updated_at' => now()]);

            return $callback(RatingGroup::query()->findOrFail($groupId));
        }, attempts: 5);
    }
}
