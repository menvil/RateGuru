<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use Illuminate\Database\Eloquent\Builder;

final class ApprovedRawQuery
{
    public function apply(Builder $query): Builder
    {
        return $query->whereRaw('score > 0');
    }
}
