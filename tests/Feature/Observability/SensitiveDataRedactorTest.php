<?php

use App\Support\Observability\SensitiveDataRedactor;

it('redacts sensitive keys from log context', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'email' => 'user@example.com',
        'password' => 'secret',
        'token' => 'abc',
        'nested' => [
            'authorization' => 'Bearer token',
        ],
    ]);

    expect($redacted['password'])->toBe('[redacted]');
    expect($redacted['token'])->toBe('[redacted]');
    expect($redacted['nested']['authorization'])->toBe('[redacted]');
});

it('preserves non-sensitive fields', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'user_id' => 42,
        'post_id' => 99,
        'password' => 'secret',
    ]);

    expect($redacted['user_id'])->toBe(42);
    expect($redacted['post_id'])->toBe(99);
    expect($redacted['password'])->toBe('[redacted]');
});

it('redacts case-insensitive keys', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'PASSWORD' => 'secret',
        'Token' => 'abc',
    ]);

    expect($redacted['PASSWORD'])->toBe('[redacted]');
    expect($redacted['Token'])->toBe('[redacted]');
});

it('redacts _token key', function () {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        '_token' => 'csrf-value',
        'safe' => 'value',
    ]);

    expect($redacted['_token'])->toBe('[redacted]');
    expect($redacted['safe'])->toBe('value');
});

it('does not mutate original input', function () {
    $original = ['password' => 'secret'];

    app(SensitiveDataRedactor::class)->redact($original);

    expect($original['password'])->toBe('secret');
});
