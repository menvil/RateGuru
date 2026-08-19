<?php

namespace App\Actions\Users;

use App\Enums\ThemePreference;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateThemePreferenceAction
{
    use LocksActorForWrite;

    public function handle(User $user, ThemePreference $preference): void
    {
        // Private-preference write: living accounts only; a stale request
        // must never persist into a Deleted tombstone. Silent skip — the
        // client-side theme already applied for the current visitor.
        DB::transaction(function () use ($user, $preference): void {
            $locked = $this->lockActor($user);

            if ($locked === null || ! $locked->canAuthenticate()) {
                return;
            }

            $locked->forceFill(['theme_preference' => $preference->value])->save();
        });
    }
}
