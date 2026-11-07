<?php

namespace App\Actions\Profile;

use App\Exceptions\Profile\CannotUpdateProfileException;
use App\Models\User;
use App\Support\Database\UniqueColumnViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateUserIdentityAction
{
    /**
     * @param  array{name: string, username: string, email: string}  $validated
     *
     * @throws ValidationException when another account claimed the username or address after the form checked it
     */
    public function execute(User $user, array $validated): void
    {
        try {
            $this->write($user, $validated);
        } catch (QueryException $exception) {
            $taken = UniqueColumnViolation::among($exception, 'users', ['username', 'email']);

            if ($taken === null) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                $taken => __('validation.unique', ['attribute' => __("validation.attributes.{$taken}")]),
            ]);
        }
    }

    /** @param array{name: string, username: string, email: string} $validated */
    private function write(User $user, array $validated): void
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
