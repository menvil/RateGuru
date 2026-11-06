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
 *  5. English, the default — only when nothing above matches
 *
 * A stored value the project does not offer is skipped, never deleted: the
 * search goes on to the next place, and the account, session and cookie keep
 * the value, so it applies again the day the language is offered again.
 * Nothing here writes anything — a language guessed from the browser serves
 * this request only.
 *
 * The admin panel is pinned to English by SetAdminLocale, which is in the
 * panel's own middleware stack. Not quite "the panel never reaches this":
 * Livewire's update endpoint is registered in the `web` group, so a panel
 * Livewire request does run this first — and then Livewire re-applies the
 * middleware recorded in the signed snapshot, which puts SetAdminLocale back
 * in charge. English wins either way; it is just not because this never ran.
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
            ?? $this->localeManager->default();
    }
}
