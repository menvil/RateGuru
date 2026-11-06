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
        // A preference can only be set to a language the project offers.
        if (! $this->locales->isEnabled($locale)) {
            throw new InvalidArgumentException("Locale [{$locale}] is not offered by this project.");
        }

        $user->update(['locale' => $locale]);
    }
}
