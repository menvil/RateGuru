<?php

namespace App\Http\Middleware;

use App\Support\Locale\LocaleManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private LocaleManager $localeManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        app()->setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        if ($request->user() && $request->user()->locale) {
            $locale = $request->user()->locale;
            if ($this->localeManager->isSupported($locale)) {
                return $locale;
            }
        }

        if ($request->session()->has('locale')) {
            $locale = $request->session()->get('locale');
            if ($this->localeManager->isSupported($locale)) {
                return $locale;
            }
        }

        if ($request->cookie('locale')) {
            $locale = $request->cookie('locale');
            if ($this->localeManager->isSupported($locale)) {
                return $locale;
            }
        }

        return $this->localeManager->fallback();
    }
}
