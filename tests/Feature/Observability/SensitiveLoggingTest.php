<?php

use App\Support\Observability\SensitiveDataRedactor;

it('does not log password_confirmation', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'password_confirmation' => 'my-password',
    ]);

    expect($redacted['password_confirmation'])->toBe('[redacted]');
});

it('does not log authorization header value', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'authorization' => 'Bearer secret-api-token',
    ]);

    expect(json_encode($redacted))->not->toContain('Bearer secret-api-token');
});

it('does not log cookie values', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'cookie' => 'session=abc; remember=xyz',
    ]);

    expect(json_encode($redacted))->not->toContain('session=abc');
});

it('does not log remember_token', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'remember_token' => 'long-random-token-value',
    ]);

    expect(json_encode($redacted))->not->toContain('long-random-token-value');
});
