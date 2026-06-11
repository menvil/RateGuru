<?php

it('has observability config with safe defaults', function () {
    expect(config('observability.request_id.header'))->toBe('X-Request-Id');
    expect(config('observability.slow_actions.enabled'))->toBeTrue();
    expect(config('observability.redaction.enabled'))->toBeTrue();
});

it('has slow action thresholds configured', function () {
    expect(config('observability.slow_actions.default_threshold_ms'))->toBeInt();
    expect(config('observability.slow_actions.external_fetch_threshold_ms'))->toBeInt();
});

it('has redaction keys configured', function () {
    $keys = config('observability.redaction.keys');

    expect($keys)->toContain('password');
    expect($keys)->toContain('token');
    expect($keys)->toContain('_token');
});

it('has security events enabled by default', function () {
    expect(config('observability.security_events.enabled'))->toBeTrue();
});
