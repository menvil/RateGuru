<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fixtures;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;

final class TraitValidationController
{
    use ValidatesRequests;

    public function store(Request $payload): void
    {
        $this->validate($payload, ['name' => ['required']]);
    }
}
