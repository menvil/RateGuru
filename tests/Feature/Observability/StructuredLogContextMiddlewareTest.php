<?php

use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** The context of the first line logged as $message during the test. */
function loggedContext(string $message, Closure $during): array
{
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $line) use (&$lines): void {
        $lines[] = $line;
    });

    $during();

    $line = collect($lines)->first(fn (MessageLogged $line): bool => $line->message === $message);

    expect($line)->not->toBeNull("nothing was logged as {$message}");

    return $line->context;
}

it('includes request id in shared log context', function () {
    Log::spy();

    $this->withHeader('X-Request-Id', 'test-log-ctx-id')
        ->get(route('feed'));

    Log::shouldHaveReceived('withContext')->withArgs(function ($context) {
        return isset($context['request_id']);
    })->atLeast()->once();
});

it('tells the log who is asking and in which language once the session has answered', function () {
    // A visitor signed in the way a browser is: a stored session and its
    // cookie. actingAs() and withSession() both hand the user over before the
    // request starts, which is exactly what hides a context read too early.
    // Written by a copy of the session store, in its configured format and
    // encryption, into the handler the request reads; the store the request
    // uses holds nothing of it.
    $user = User::factory()->create(['locale' => 'ru', 'theme_preference' => 'dark']);
    $sessionId = Str::random(40);
    $fixture = clone app('session')->driver();
    $fixture->setId($sessionId);
    $fixture->put(Auth::guard('web')->getName(), $user->id);
    $fixture->save();
    Route::middleware('web')->get('/log-context-probe', function () {
        Log::info('log.context.probe');

        return 'ok';
    });

    $context = loggedContext('log.context.probe', fn () => $this
        ->withHeader('X-Request-Id', 'probe-request')
        ->withCookie(config('session.cookie'), $sessionId)
        ->get('/log-context-probe')
        ->assertOk());

    expect($context)->toMatchArray([
        'request_id' => 'probe-request',
        'user_id' => $user->id,
        'locale' => 'ru',
        'theme_preference' => 'dark',
    ]);
});

it('tells the log nobody is signed in for a guest, in the language the guest chose', function () {
    Route::middleware('web')->get('/log-context-probe', function () {
        Log::info('log.context.probe');

        return 'ok';
    });

    $context = loggedContext('log.context.probe', fn () => $this
        ->withHeader('Accept-Language', 'bg')
        ->get('/log-context-probe')
        ->assertOk());

    expect($context)->toHaveKey('request_id')
        ->not->toHaveKey('user_id')
        ->and($context['locale'])->toBe('bg');
});
