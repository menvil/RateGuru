<?php

use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Profile\DeleteUserAccountAction;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * profile.account_anonymized is the record that an account became a tombstone.
 * It must mean exactly that: the anonymization is in the database, not merely
 * in a savepoint a surrounding transaction is still free to roll back.
 */

it('does not log an anonymization that an outer rollback undid', function () {
    Log::spy();

    $user = User::factory()->create();

    DB::transaction(function () use ($user): void {
        app(DeleteUserAccountAction::class)->execute($user);

        // The caller decides the whole operation did not happen.
        DB::rollBack();
    });

    expect(User::query()->whereKey($user->getKey())->firstOrFail()->status)
        ->not->toBe(UserStatus::Deleted);

    Log::shouldNotHaveReceived('info', ['profile.account_anonymized', Mockery::any()]);
});

it('logs an anonymization once the outermost transaction has committed', function () {
    Log::spy();

    $user = User::factory()->create();

    DB::transaction(fn () => app(DeleteUserAccountAction::class)->execute($user));

    expect(User::query()->whereKey($user->getKey())->firstOrFail()->status)
        ->toBe(UserStatus::Deleted);

    Log::shouldHaveReceived('info')
        ->with('profile.account_anonymized', Mockery::on(
            fn ($context) => ($context['user_id'] ?? null) === $user->getKey(),
        ));
});

it('logs immediately when nothing wraps the anonymization', function () {
    Log::spy();

    $user = User::factory()->create();

    app(AnonymizeUserAccountAction::class)->execute($user);

    // DB::afterCommit outside a transaction runs its callback at once, so the
    // direct caller's behaviour is unchanged.
    Log::shouldHaveReceived('info')
        ->with('profile.account_anonymized', Mockery::on(
            fn ($context) => ($context['user_id'] ?? null) === $user->getKey(),
        ));
});
