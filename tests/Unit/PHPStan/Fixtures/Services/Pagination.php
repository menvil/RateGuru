<?php

declare(strict_types=1);

namespace App\Services\ArchitectureFixtures;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class PaginatingService
{
    public function posts(Builder $query): LengthAwarePaginator
    {
        return $query->orderByDesc('id')->paginate();
    }
}
