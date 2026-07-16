<?php

namespace App\Http\Controllers\Locale;

use App\Actions\Locale\ChangeLocaleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeLocaleRequest;
use Illuminate\Http\RedirectResponse;

class ChangeLocaleController extends Controller
{
    public function __invoke(ChangeLocaleRequest $request, ChangeLocaleAction $action): RedirectResponse
    {
        $validated = $request->validated();

        $action->execute($validated['locale'], $request);

        return redirect()->back();
    }
}
