<?php

use App\Support\Observability\SlowActionLogger;
use Illuminate\Support\Facades\Log;

it('logs slow action when threshold exceeded', function () {
    Log::spy();

    app(SlowActionLogger::class)->measure('test.slow_action', function () {
        usleep(20_000);

        return 'done';
    }, thresholdMs: 1);

    Log::shouldHaveReceived('warning')
        ->with('test.slow_action.slow', Mockery::on(fn ($context) => $context['duration_ms'] >= 1));
});

it('does not log slow action when below threshold', function () {
    Log::spy();

    app(SlowActionLogger::class)->measure('test.fast_action', function () {
        return 'done';
    }, thresholdMs: 99999);

    Log::shouldNotHaveReceived('warning');
});

it('returns callback result', function () {
    $result = app(SlowActionLogger::class)->measure('test.action', function () {
        return 'callback-result';
    }, thresholdMs: 99999);

    expect($result)->toBe('callback-result');
});

it('rethrows exceptions from callback', function () {
    expect(fn () => app(SlowActionLogger::class)->measure('test.action', function () {
        throw new RuntimeException('Something failed');
    }, thresholdMs: 1))->toThrow(RuntimeException::class, 'Something failed');
});

it('includes threshold_ms in slow action log', function () {
    Log::spy();

    app(SlowActionLogger::class)->measure('test.slow', function () {
        usleep(20_000);
    }, thresholdMs: 1);

    Log::shouldHaveReceived('warning')
        ->with('test.slow.slow', Mockery::on(fn ($context) => isset($context['threshold_ms'])));
});

it('uses default threshold from config when not specified', function () {
    Log::spy();

    $defaultMs = config('observability.slow_actions.default_threshold_ms', 500);

    app(SlowActionLogger::class)->measure('test.default', function () {
    });

    Log::shouldNotHaveReceived('warning');
});
