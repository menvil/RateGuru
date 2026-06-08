<?php

namespace App\Http\Controllers\Locale;

use App\Actions\Locale\ChangeLocaleAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChangeLocaleController extends Controller
{
    public function __invoke(Request $request, ChangeLocaleAction $action): RedirectResponse
    {
        $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(config('locales.supported', [])))],
        ]);

        $action->execute($request->input('locale'), $request);

        return redirect()->back();
    }
}
