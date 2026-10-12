<?php

use App\Exceptions\Abuse\RateLimitExceededException;
use App\Models\User;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use Illuminate\Support\Facades\RateLimiter;

it('gives every throttled action a positive attempt limit and decay window', function () {
    // config/rate_limits.php casts each env value to int: an empty value becomes
    // 0, and a limit of 0 refuses the action on its very first attempt.
    foreach (['upload', 'comment', 'report', 'vote'] as $action) {
        foreach (['max_attempts', 'decay_seconds'] as $setting) {
            $key = "rate_limits.{$action}.{$setting}";

            expect(config($key))->toBeInt($key)->toBeGreaterThan(0, $key);
        }
    }
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
