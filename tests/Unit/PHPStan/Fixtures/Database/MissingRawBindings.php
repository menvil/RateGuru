<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use Illuminate\Database\Eloquent\Builder;

final class MissingRawBindings
{
    public function apply(Builder $query): Builder
    {
        return $query->whereRaw('score > ?');
    }
}
