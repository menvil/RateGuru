<?php

namespace App\Http\Controllers\Locale;

use App\Actions\Locale\ChangeLocaleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeLocaleRequest;
use Illuminate\Http\RedirectResponse;

class ChangeLocaleController extends Controller
{
    /** How long an explicit choice outlives the session. */
    private const COOKIE_MINUTES = 60 * 24 * 365;

    public function __invoke(ChangeLocaleRequest $request, ChangeLocaleAction $action): RedirectResponse
    {
        $locale = $action->execute($request->validated('locale'), $request);

        // Remembered past the session so a guest keeps the language on the next
        // visit. Only ever set here, by an explicit choice — a language guessed
        // from the browser is never written anywhere. Encrypted like every
        // cookie in the web group, and never read by JavaScript.
        return redirect()->back()->withCookie(cookie(
            name: 'locale',
            value: $locale,
            minutes: self::COOKIE_MINUTES,
            path: '/',
            httpOnly: true,
            sameSite: 'lax',
        ));
    }
}
