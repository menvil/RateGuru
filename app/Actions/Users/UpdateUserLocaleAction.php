<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Locale\LocaleManager;
use InvalidArgumentException;

final class UpdateUserLocaleAction
{
    public function __construct(private readonly LocaleManager $locales) {}

    public function handle(User $user, string $locale): void
    {
        if (! $this->locales->isSupported($locale)) {
            throw new InvalidArgumentException("Unsupported locale: [{$locale}].");
        }

        $user->update(['locale' => $locale]);
    }
}
