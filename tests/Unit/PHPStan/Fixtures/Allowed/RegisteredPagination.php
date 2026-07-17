<?php

declare(strict_types=1);

namespace App\Queries\ArchitectureFixtures;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class RegisteredPaginatedQuery
{
    public function paginate(Builder $query): LengthAwarePaginator
    {
        return $query->orderByDesc('id')->paginate();
    }
}
