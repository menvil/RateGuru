<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;

final class UnapprovedRawQuery
{
    public function apply(Builder $query): Builder
    {
        Post::whereRaw('score > 0')->count();

        return $query
            ->whereRaw('score > 0')
            ->orderByRaw('score DESC');
    }
}
