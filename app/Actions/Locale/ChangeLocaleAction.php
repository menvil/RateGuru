<?php

namespace App\Actions\Locale;

use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ChangeLocaleAction
{
    use LocksActorForWrite;

    public function __construct(private LocaleManager $localeManager) {}

    /**
     * Records an explicit choice of language and returns it, for the caller to
     * remember in the visitor's cookie as well.
     */
    public function execute(string $locale, Request $request): string
    {
        // An explicit choice is taken as made or refused, never swapped for
        // another language: a visitor who asked for one must not silently get
        // a different one saved.
        if (! $this->localeManager->isEnabled($locale)) {
            throw new InvalidArgumentException("Locale [{$locale}] is not offered by this project.");
        }

        // Session-local locale always applies for the current visitor.
        $request->session()->put('locale', $locale);

        $user = $request->user();

        if (! $user instanceof User) {
            return $locale;
        }

        // Persisting is a private-preference write: a stale authenticated
        // request must never write into a Deleted tombstone. Silent skip —
        // the session locale above already served the UX.
        DB::transaction(function () use ($user, $locale): void {
            $locked = $this->lockActor($user);

            if ($locked === null || ! $locked->canAuthenticate()) {
                return;
            }

            $locked->forceFill(['locale' => $locale])->save();
        });

        return $locale;
    }
}
