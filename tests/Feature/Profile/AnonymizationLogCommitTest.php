<?php

use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Profile\DeleteUserAccountAction;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * profile.account_anonymized is the record that an account became a tombstone.
 * It must mean exactly that: the anonymization is in the database, not merely
 * in a savepoint a surrounding transaction is still free to roll back.
 *
 * Captured through Log::listen against the real logger rather than asserted on a
 * Mockery spy. A spy's negative assertion is easy to write in a shape that
 * cannot fail — `shouldNotHaveReceived('info')` chained with `->with()` returns
 * null through the facade and dies, and dropping the `->with()` broadens the
 * claim to "info was never called at all" — and the whole value of this file is
 * one assertion that a log line is ABSENT. A plain list of what was logged
 * cannot be read two ways.
 */

it('does not log an anonymization that an outer rollback undid', function () {
    $events = [];
    Log::listen(function (MessageLogged $logged) use (&$events): void {
        $events[] = $logged->message;
    });

    $user = User::factory()->create();

    // Thrown from inside the closure, not DB::rollBack() called by hand. That is
    // the pattern the repository already uses for this situation (see
    // CreatePostActionMediaVariantDispatchTest), and the reason is that
    // DB::transaction performs its OWN rollback for an exception and discards the
    // afterCommit callbacks as part of it. A bare DB::rollBack() unwinds to the
    // previous savepoint level — RefreshDatabase holds one open on this
    // connection — and leaves whether those callbacks still fire to transaction
    // bookkeeping rather than to the contract under test.
    try {
        DB::transaction(function () use ($user): void {
            app(DeleteUserAccountAction::class)->execute($user);

            throw new RuntimeException('Simulated outer rollback.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated outer rollback.');
    }

    expect(User::query()->whereKey($user->getKey())->firstOrFail()->status)
        ->not->toBe(UserStatus::Deleted)
        ->and($events)->not->toContain('profile.account_anonymized');
});

it('logs an anonymization once the outermost transaction has committed', function () {
    $events = [];
    Log::listen(function (MessageLogged $logged) use (&$events): void {
        $events[] = $logged->message;
    });

    $user = User::factory()->create();

    DB::transaction(fn () => app(DeleteUserAccountAction::class)->execute($user));

    expect(User::query()->whereKey($user->getKey())->firstOrFail()->status)
        ->toBe(UserStatus::Deleted)
        ->and($events)->toContain('profile.account_anonymized');
});

it('logs immediately when nothing wraps the anonymization', function () {
    $events = [];
    Log::listen(function (MessageLogged $logged) use (&$events): void {
        $events[] = $logged->message;
    });

    $user = User::factory()->create();

    app(AnonymizeUserAccountAction::class)->execute($user);

    // DB::afterCommit outside a transaction runs its callback at once, so the
    // direct caller's behaviour is unchanged.
    expect($events)->toContain('profile.account_anonymized');
});

it('carries the anonymized account in the log context', function () {
    $contexts = [];
    Log::listen(function (MessageLogged $logged) use (&$contexts): void {
        if ($logged->message === 'profile.account_anonymized') {
            $contexts[] = $logged->context;
        }
    });

    $user = User::factory()->create();

    app(AnonymizeUserAccountAction::class)->execute($user);

    expect($contexts)->toHaveCount(1)
        ->and($contexts[0]['user_id'] ?? null)->toBe($user->getKey())
        // PII-free, deliberately: the record of a tombstone must not preserve
        // what the tombstone erased.
        ->and(json_encode($contexts[0]))->not->toContain($user->email);
});
