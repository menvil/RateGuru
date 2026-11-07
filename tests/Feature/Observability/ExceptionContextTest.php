<?php

use App\Models\User;
use App\Support\Observability\ExceptionContextBuilder;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

it('adds request id to exception context', function () {
    $context = app(ExceptionContextBuilder::class)->build(new RuntimeException('Test'));

    expect($context)->toHaveKey('request_id');
});

it('adds app_env to exception context', function () {
    $context = app(ExceptionContextBuilder::class)->build(new RuntimeException('Test'));

    expect($context)->toHaveKey('app_env');
});

it('adds exception_class to context', function () {
    $context = app(ExceptionContextBuilder::class)->build(new RuntimeException('Test'));

    expect($context['exception_class'])->toBe(RuntimeException::class);
});

it('adds user id when authenticated', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('feed'));

    $context = app(ExceptionContextBuilder::class)->build(new RuntimeException('Test'));

    expect($context['user_id'])->toBe($user->id);
});

it('does not include password or token in exception context', function () {
    $context = app(ExceptionContextBuilder::class)->build(new RuntimeException('Test'));

    expect($context)->not->toHaveKey('password');
    expect($context)->not->toHaveKey('token');
    expect($context)->not->toHaveKey('_token');
});

it('still reports the original failure when the user cannot be looked up', function () {
    // The database going away is the usual reason for both: the failure being
    // reported, and the session guard being unable to load its user.
    Auth::extend('unreachable', fn (): Guard => new class implements Guard
    {
        use GuardHelpers;

        public function user()
        {
            throw new RuntimeException('The users table is unreachable.');
        }

        public function validate(array $credentials = []): bool
        {
            return false;
        }
    });
    config(['auth.guards.web.driver' => 'unreachable']);
    Auth::forgetGuards();
    Log::spy();

    app(ExceptionHandler::class)->report(new RuntimeException('The original failure.'));

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'The original failure.'
        && $context['exception_class'] === RuntimeException::class
        && isset($context['request_id'])
        && ! array_key_exists('user_id', $context))->once();
});
