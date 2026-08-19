<?php

namespace App\Actions\Locale;

use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChangeLocaleAction
{
    use LocksActorForWrite;

    public function __construct(private LocaleManager $localeManager) {}

    public function execute(string $locale, Request $request): void
    {
        $locale = $this->localeManager->normalize($locale);

        // Session-local locale always applies for the current visitor.
        $request->session()->put('locale', $locale);

        $user = $request->user();

        if (! $user instanceof User) {
            return;
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
    }
}
