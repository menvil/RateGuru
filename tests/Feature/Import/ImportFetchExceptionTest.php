<?php

use App\Exceptions\Import\ImportFetchException;

it('never leaks a credential-bearing url embedded in the reason text', function () {
    $reason = 'cURL error 6: Could not resolve host: good.example for https://good.example/page?token=SECRET123';

    $exception = ImportFetchException::connectionError('https://good.example/page?token=SECRET123', $reason);

    expect($exception->getMessage())->not->toContain('SECRET123');
});

it('never leaks a credential-bearing url through the chained previous exception either', function () {
    // The previous exception's own message is entirely outside our
    // control (it's whatever curl/Guzzle produced) — Laravel's default
    // exception logging walks the full chain and prints every message in
    // it, so this is a real, separate leak path from the outer message
    // sanitized above.
    $rawPrevious = new RuntimeException(
        'cURL error 6: Could not resolve host: good.example for https://good.example/page?token=SECRET123'
    );

    $exception = ImportFetchException::connectionError(
        'https://good.example/page?token=SECRET123',
        $rawPrevious->getMessage(),
        $rawPrevious,
    );

    expect($exception->getMessage())->not->toContain('SECRET123')
        ->and($exception->getPrevious())->not->toBeNull()
        ->and($exception->getPrevious())->not->toBe($rawPrevious)
        ->and($exception->getPrevious()->getMessage())->not->toContain('SECRET123');
});

it('still preserves the original exception class name for diagnostics, even though the raw instance is not chained', function () {
    $rawPrevious = new RuntimeException('cURL error 28: Operation timed out for https://good.example/page');

    $exception = ImportFetchException::connectionError('https://good.example/page', $rawPrevious->getMessage(), $rawPrevious);

    expect($exception->getPrevious()->getMessage())->toContain(RuntimeException::class);
});

it('leaves getPrevious() null when no previous exception was given', function () {
    $exception = ImportFetchException::connectionError('https://good.example/page', 'simulated failure');

    expect($exception->getPrevious())->toBeNull();
});
