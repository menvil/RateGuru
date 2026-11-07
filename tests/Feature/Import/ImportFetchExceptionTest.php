<?php

use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;

it('never leaks a credential-bearing url embedded in the reason text', function () {
    $url = 'https://example.com/image.jpg?token=super-secret-value';
    $reason = "cURL error 6: Could not resolve host: example.com for {$url}";

    $exception = ImportFetchException::connectionError($url, $reason);

    expect($exception->getMessage())->not->toContain('super-secret-value');
});

it('never chains the raw transport exception as previous, since its own message is outside our sanitization', function () {
    // The raw exception's own message is entirely outside our control
    // (it's whatever curl/Guzzle produced, commonly embedding the full
    // request URL verbatim) — Laravel's default exception logging walks
    // the full previous-exception chain and prints every message in it,
    // so chaining it (even wrapped) would be a second leak path separate
    // from the outer message sanitized above. connectionError() no
    // longer accepts a $previous argument at all: getPrevious() must
    // always be null for this exception.
    $url = 'https://example.com/image.jpg?token=super-secret-value';
    $rawPrevious = new RuntimeException("cURL error 6: Could not resolve host: example.com for {$url}");

    $exception = ImportFetchException::connectionError($url, $rawPrevious->getMessage());

    expect($exception->getMessage())->not->toContain('super-secret-value')
        ->and($exception->getPrevious())->toBeNull();
});

it('leaves getPrevious() null for every factory, not just connectionError', function () {
    expect(ImportFetchException::requestFailed('https://example.com/page', 500)->getPrevious())->toBeNull()
        ->and(ImportFetchException::responseTooLarge('https://example.com/page', 1024)->getPrevious())->toBeNull()
        ->and(ImportFetchException::tooManyRedirects('https://example.com/page')->getPrevious())->toBeNull()
        ->and(ImportFetchException::missingRedirectLocation('https://example.com/page')->getPrevious())->toBeNull();
});

it('keeps a pasted link\'s whole query out of the message, whatever its parameters are called', function (string $query, string $secret) {
    $exception = ImportFetchException::requestFailed("https://cdn.example.com/photo.jpg?{$query}", 403);

    expect($exception->getMessage())->toContain('https://cdn.example.com/photo.jpg')
        ->not->toContain($secret)
        ->not->toContain('?');
})->with([
    'a presigned S3 link' => ['X-Amz-Signature=sig-sentinel&X-Amz-Expires=300', 'sig-sentinel'],
    'a short signature' => ['sig=sig-sentinel', 'sig-sentinel'],
    'an HMAC under another name' => ['hm=sig-sentinel', 'sig-sentinel'],
    'a nested token' => ['a%5Btoken%5D=sig-sentinel', 'sig-sentinel'],
]);

it('says an import url is invalid without repeating it', function () {
    $exception = UnsafeImportUrlException::invalidUrl('https://example.com/a.jpg?token=sig-sentinel');

    expect($exception->getMessage())->not->toContain('sig-sentinel')
        ->not->toContain('example.com');
});
