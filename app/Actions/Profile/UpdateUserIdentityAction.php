<?php

namespace App\Actions\Profile;

use App\Actions\Auth\SendEmailVerificationNotificationAction;
use App\Exceptions\Profile\CannotUpdateProfileException;
use App\Models\User;
use App\Support\Database\UniqueColumnViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateUserIdentityAction
{
    public function __construct(
        private readonly SendEmailVerificationNotificationAction $sendConfirmation,
    ) {}

    /**
     * @param  array{name: string, username: string, email: string}  $validated
     *
     * @throws ValidationException when another account claimed the username or address after the form checked it
     * @throws CannotUpdateProfileException when the account may not change its profile
     */
    public function execute(User $user, array $validated): void
    {
        try {
            $emailChanged = $this->write($user, $validated);
        } catch (QueryException $exception) {
            $taken = UniqueColumnViolation::among($exception, 'users', ['username', 'email']);

            if ($taken === null) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                $taken => __('validation.unique', ['attribute' => __("validation.attributes.{$taken}")]),
            ]);
        }

        // After the commit: the link is for the address now on the account.
        if ($emailChanged) {
            $this->sendConfirmation->attempt($user);
        }
    }

    /**
     * @param  array{name: string, username: string, email: string}  $validated
     * @return bool whether the address changed
     */
    private function write(User $user, array $validated): bool
    {
        // Re-read under lock and check the *current* lifecycle state: a
        // stale session's in-flight update racing account anonymization
        // must never write identity back into a committed tombstone.
        return DB::transaction(function () use ($user, $validated): bool {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->canUpdateProfile()) {
                throw CannotUpdateProfileException::becauseAccountIsNotEditable();
            }

            $locked->fill($validated);

            $emailChanged = $locked->isDirty('email');

            if ($emailChanged) {
                $locked->email_verified_at = null;
            }

            $locked->save();

            $user->setRawAttributes($locked->getAttributes(), true);

            return $emailChanged;
        });
    }
}
