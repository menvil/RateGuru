<?php

use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\UrlImportValidator;

it('rejects localhost urls for import', function () {
    app(UrlImportValidator::class)->validate('http://localhost/test');
})->throws(UnsafeImportUrlException::class);

it('rejects private ip urls for import', function () {
    app(UrlImportValidator::class)->validate('http://192.168.1.1/image.jpg');
})->throws(UnsafeImportUrlException::class);

it('rejects 10.x private range', function () {
    app(UrlImportValidator::class)->validate('http://10.0.0.1/image.jpg');
})->throws(UnsafeImportUrlException::class);

it('rejects 172.16.x private range', function () {
    app(UrlImportValidator::class)->validate('http://172.16.0.1/image.jpg');
})->throws(UnsafeImportUrlException::class);

it('rejects link-local addresses', function () {
    app(UrlImportValidator::class)->validate('http://169.254.169.254/latest/meta-data');
})->throws(UnsafeImportUrlException::class);

it('rejects loopback ipv4', function () {
    app(UrlImportValidator::class)->validate('http://127.0.0.1/test');
})->throws(UnsafeImportUrlException::class);

it('rejects file scheme', function () {
    app(UrlImportValidator::class)->validate('file:///etc/passwd');
})->throws(UnsafeImportUrlException::class);

it('rejects ftp scheme', function () {
    app(UrlImportValidator::class)->validate('ftp://example.com/file.jpg');
})->throws(UnsafeImportUrlException::class);

it('rejects invalid url', function () {
    app(UrlImportValidator::class)->validate('not-a-url');
})->throws(UnsafeImportUrlException::class);

it('allows normal https urls', function () {
    $url = app(UrlImportValidator::class)->validate('https://example.com/page');

    expect($url)->toBe('https://example.com/page');
});

it('allows normal http urls', function () {
    $url = app(UrlImportValidator::class)->validate('http://example.com/image.jpg');

    expect($url)->toBe('http://example.com/image.jpg');
});
