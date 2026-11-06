<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel is English, whatever language its user reads the site in.
 *
 * Moderation and settings are operator tools, written and supported in English
 * only; translating them into every public language would make each new
 * language twice the work. So the panel does not consult the visitor's language
 * at all — it sets English for this request and nothing more.
 *
 * "Nothing more" is the point: this never writes the session, the cookie or the
 * account's `locale`, so the public site is back in the reader's own language on
 * the very next request.
 *
 * Actions inside the panel are Livewire updates, which pass through the `web`
 * group and SetLocale rather than through here. They still render in English:
 * Livewire records the locale a component was rendered in inside its signed
 * snapshot and restores it on every update, and every panel component is first
 * rendered under this middleware.
 */
class SetAdminLocale
{
    public const LOCALE = 'en';

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(self::LOCALE);

        return $next($request);
    }
}
