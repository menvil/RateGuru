<?php

use App\Support\Observability\SensitiveDataRedactor;

it('does not log raw password values', function () {
    $context = [
        'password' => 'super-secret-password',
        'safe' => 'value',
    ];

    $redacted = app(SensitiveDataRedactor::class)->redact($context);

    expect(json_encode($redacted))->not->toContain('super-secret-password');
});

it('does not log password_confirmation', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'password_confirmation' => 'my-password',
    ]);

    expect($redacted['password_confirmation'])->toBe('[redacted]');
});

it('does not log _token csrf value', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        '_token' => 'csrf-abc123',
    ]);

    expect(json_encode($redacted))->not->toContain('csrf-abc123');
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

it('does not log token field', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'token' => 'api-secret-token',
    ]);

    expect(json_encode($redacted))->not->toContain('api-secret-token');
});

it('preserves safe context fields after redaction', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'user_id' => 42,
        'post_id' => 99,
        'event' => 'posts.created',
        'password' => 'secret',
    ]);

    expect($redacted['user_id'])->toBe(42);
    expect($redacted['post_id'])->toBe(99);
    expect($redacted['event'])->toBe('posts.created');
    expect($redacted['password'])->toBe('[redacted]');
});

it('redacts sensitive fields nested in request data', function () {
    $requestData = [
        'display_name' => 'Ivan',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
        '_token' => 'csrf-token',
    ];

    $redacted = app(SensitiveDataRedactor::class)->redact($requestData);

    expect($redacted['display_name'])->toBe('Ivan');
    expect(json_encode($redacted))->not->toContain('new-password');
    expect(json_encode($redacted))->not->toContain('csrf-token');
});
