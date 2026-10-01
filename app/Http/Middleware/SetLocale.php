<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locale\LocaleManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language a public request is served in.
 *
 * A choice the visitor made comes first, from the first place it is stored:
 *
 *  1. the account's chosen language
 *  2. the session — a choice made earlier in this visit
 *  3. the `locale` cookie — a choice made on an earlier visit
 *
 * The first choice found decides. Offered, it is served; not offered — the
 * language was disabled after it was chosen — the visitor gets the default,
 * English. The search does not go on to an older choice or to the browser:
 * those would hand a visitor who chose Bulgarian some other language they
 * never picked. The choice itself is kept, never deleted, so it applies again
 * the day the language is offered again.
 *
 * Only a visitor who has chosen nothing is served by their browser:
 *
 *  4. the browser's Accept-Language, in its quality order
 *  5. the default, English
 *
 * Nothing here writes anything — a language guessed from the browser serves
 * this request only.
 *
 * The admin panel does not go through this at all; SetAdminLocale pins it to
 * English.
 */
class SetLocale
{
    public function __construct(private LocaleManager $localeManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $chosen = $this->chosenLocale($request);

        if ($chosen !== null) {
            return $this->localeManager->enabledOrDefault($chosen);
        }

        return $this->localeManager->fromAcceptLanguage($request->header('Accept-Language'))
            ?? $this->localeManager->default();
    }

    /** The first language the visitor chose, wherever it is stored, offered or not. */
    private function chosenLocale(Request $request): ?string
    {
        $user = $request->user();

        $stored = [
            $user instanceof User ? $user->locale : null,
            $request->session()->get('locale'),
            $request->cookie('locale'),
        ];

        foreach ($stored as $locale) {
            if (is_string($locale) && trim($locale) !== '') {
                return $locale;
            }
        }

        return null;
    }
}
