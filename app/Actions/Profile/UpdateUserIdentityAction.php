<?php

namespace App\Actions\Profile;

use App\Exceptions\Profile\CannotUpdateProfileException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateUserIdentityAction
{
    /** @param array{name: string, username: string, email: string} $validated */
    public function execute(User $user, array $validated): void
    {
        // Re-read under lock and check the *current* lifecycle state: a
        // stale session's in-flight update racing account anonymization
        // must never write identity back into a committed tombstone.
        DB::transaction(function () use ($user, $validated): void {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->canUpdateProfile()) {
                throw CannotUpdateProfileException::becauseAccountIsNotEditable();
            }

            $locked->fill($validated);

            if ($locked->isDirty('email')) {
                $locked->email_verified_at = null;
            }

            $locked->save();

            $user->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
