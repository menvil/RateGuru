<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

final class InlineValidationController
{
    public function requestValidation(Request $payload): void
    {
        $payload->validate(['name' => ['required']]);
        Auth::guard()->validate([]);
    }

    public function facadeValidation(): void
    {
        Validator::make([], []);
    }

    public function helperValidation(): void
    {
        validator([], []);
    }
}

namespace App\Livewire\Fixtures;

use Illuminate\Http\Request;

final class InlineValidationComponent
{
    public function validateOutsideController(Request $request): void
    {
        $request->validate([]);
    }
}
