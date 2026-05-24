<?php

use App\Exceptions\Abuse\RateLimitExceededException;
use App\Models\User;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use Illuminate\Support\Facades\RateLimiter;

it('has upload rate limit config', function () {
    expect(config('rate_limits.upload.max_attempts'))->toBeInt();
    expect(config('rate_limits.upload.decay_seconds'))->toBeInt();
});

it('has comment rate limit config', function () {
    expect(config('rate_limits.comment.max_attempts'))->toBeInt();
    expect(config('rate_limits.comment.decay_seconds'))->toBeInt();
});

it('has report rate limit config', function () {
    expect(config('rate_limits.report.max_attempts'))->toBeInt();
    expect(config('rate_limits.report.decay_seconds'))->toBeInt();
});

it('has vote rate limit config', function () {
    expect(config('rate_limits.vote.max_attempts'))->toBeInt();
    expect(config('rate_limits.vote.decay_seconds'))->toBeInt();
});

it('resolves action rate limiter', function () {
    expect(app(ActionRateLimiter::class))->toBeInstanceOf(ActionRateLimiter::class);
});

it('throws when action limit is exceeded', function () {
    $key = 'rate-limit:test:user:1';

    RateLimiter::clear($key);

    $limiter = app(ActionRateLimiter::class);

    $limiter->hitOrFail($key, 1, 60, 'Slow down.');

    expect(fn () => $limiter->hitOrFail($key, 1, 60, 'Slow down.'))
        ->toThrow(RateLimitExceededException::class, 'Slow down.');

    RateLimiter::clear($key);
});

it('builds user-scoped rate limit keys', function () {
    $user = User::factory()->create();

    expect(RateLimitKey::userAction('upload', $user))->toBe("rate-limit:upload:user:{$user->id}");
    expect(RateLimitKey::userTarget('comment-post', $user, 'post', 456))
        ->toBe("rate-limit:comment-post:user:{$user->id}:target:post:456");
});
