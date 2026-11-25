<?php

namespace App\Actions\Users;

use App\Actions\Languages\MarkAuthorPostsLanguageStaleAction;
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

        $changed = $user->locale !== $locale;
        $user->update(['locale' => $locale]);

        if ($changed) {
            app(MarkAuthorPostsLanguageStaleAction::class)->handle((int) $user->id);
        }
    }
}
