<?php

namespace App\Actions\Locale;

use App\Models\User;
use App\Support\Locale\LocaleManager;
use Illuminate\Http\Request;

class ChangeLocaleAction
{
    public function __construct(private LocaleManager $localeManager) {}

    public function execute(string $locale, Request $request): void
    {
        $locale = $this->localeManager->normalize($locale);

        $request->session()->put('locale', $locale);

        if ($request->user() instanceof User) {
            $request->user()->update(['locale' => $locale]);
        }
    }
}
