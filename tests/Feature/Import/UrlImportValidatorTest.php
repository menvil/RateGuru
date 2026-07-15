<?php

use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\UrlImportValidator;

it('rejects localhost urls for import', function () {
    app(UrlImportValidator::class)->validate('https://localhost/test');
})->throws(UnsafeImportUrlException::class);

it('rejects private ip urls for import', function () {
    app(UrlImportValidator::class)->validate('https://192.168.1.1/image.jpg');
})->throws(UnsafeImportUrlException::class);

it('rejects 10.x private range', function () {
    app(UrlImportValidator::class)->validate('https://10.0.0.1/image.jpg');
})->throws(UnsafeImportUrlException::class);

it('rejects 172.16.x private range', function () {
    app(UrlImportValidator::class)->validate('https://172.16.0.1/image.jpg');
})->throws(UnsafeImportUrlException::class);

it('rejects 172.31.255.255 upper bound of 172.16/12 range', function () {
    app(UrlImportValidator::class)->validate('https://172.31.255.255/image.jpg');
})->throws(UnsafeImportUrlException::class);

it('allows 172.32.0.1 which is just outside the private range', function () {
    $validator = new class extends UrlImportValidator
    {
        protected function resolveHostname(string $host): array|false
        {
            return ['172.32.0.1'];
        }
    };

    expect(fn () => $validator->validate('https://172.32.0.1/image.jpg'))->not->toThrow(UnsafeImportUrlException::class);
});

it('rejects link-local addresses', function () {
    app(UrlImportValidator::class)->validate('https://169.254.169.254/latest/meta-data');
})->throws(UnsafeImportUrlException::class);

it('rejects loopback ipv4', function () {
    app(UrlImportValidator::class)->validate('https://127.0.0.1/test');
})->throws(UnsafeImportUrlException::class);

it('rejects ipv6 loopback', function () {
    app(UrlImportValidator::class)->validate('https://[::1]/test');
})->throws(UnsafeImportUrlException::class);

it('rejects ipv6 link-local fe80 address', function () {
    app(UrlImportValidator::class)->validate('https://[fe80::1]/test');
})->throws(UnsafeImportUrlException::class);

it('rejects ipv6 unique-local fc00 address', function () {
    app(UrlImportValidator::class)->validate('https://[fc00::1]/test');
})->throws(UnsafeImportUrlException::class);

it('rejects ipv6 unique-local fd address', function () {
    app(UrlImportValidator::class)->validate('https://[fd12:3456:789a::1]/test');
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

it('rejects http scheme', function () {
    app(UrlImportValidator::class)->validate('http://example.com/image.jpg');
})->throws(UnsafeImportUrlException::class);

it('rejects hostname that resolves to private ip', function () {
    $validator = new class extends UrlImportValidator
    {
        protected function resolveHostname(string $host): array|false
        {
            return ['192.168.1.1'];
        }
    };

    expect(fn () => $validator->validate('https://evil.example.com/image.jpg'))
        ->toThrow(UnsafeImportUrlException::class);
});

it('rejects unresolvable hostname', function () {
    $validator = new class extends UrlImportValidator
    {
        protected function resolveHostname(string $host): array|false
        {
            return false;
        }
    };

    expect(fn () => $validator->validate('https://this-does-not-exist-xyz.example.com/image.jpg'))
        ->toThrow(UnsafeImportUrlException::class);
});
