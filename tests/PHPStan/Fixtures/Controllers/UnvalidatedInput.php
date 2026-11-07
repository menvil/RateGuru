<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;

final class UnvalidatedInputController
{
    /**
     * @return array{mixed, mixed, mixed, mixed, mixed, mixed, mixed, mixed}
     */
    public function __invoke(Request $payload, Builder $query): array
    {
        $query->get();

        return [
            $payload->input('status'),
            $payload->string('type'),
            $payload['page'],
            $payload->sort,
            $payload->except('token'),
            $payload->filled('search'),
            RequestFacade::input('status'),
            request('status'),
        ];
    }
}
