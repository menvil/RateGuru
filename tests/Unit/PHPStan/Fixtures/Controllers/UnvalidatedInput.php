<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class UnvalidatedInputController
{
    /**
     * @return array{mixed, mixed}
     */
    public function __invoke(Request $payload, Builder $query): array
    {
        $query->get();

        return [$payload->input('status'), $payload->string('type')];
    }
}
