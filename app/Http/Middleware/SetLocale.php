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
 * First match wins, and only a language the project offers can match:
 *
 *  1. the account's chosen language
 *  2. the session — a choice made earlier in this visit
 *  3. the `locale` cookie — a choice made on an earlier visit
 *  4. the browser's Accept-Language, in its quality order
 *  5. the project default (which falls back to the technical fallback only
 *     when the project settings themselves are unusable)
 *
 * A stored value the project does not offer is skipped, never deleted: the
 * account, session and cookie keep it, and it applies again the day the
 * language is offered again. Nothing here writes anything — a language
 * guessed from the browser serves this request only.
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
        $user = $request->user();

        $chosen = [
            $user instanceof User ? $user->locale : null,
            $request->session()->get('locale'),
            $request->cookie('locale'),
        ];

        foreach ($chosen as $locale) {
            if (is_string($locale) && $this->localeManager->isEnabled($locale)) {
                return $locale;
            }
        }

        return $this->localeManager->fromAcceptLanguage($request->header('Accept-Language'))
            ?? $this->localeManager->projectDefault();
    }
}
