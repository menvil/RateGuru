<?php

use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\ResolvedImportTarget;
use App\Support\Import\UrlImportValidator;

beforeEach(function () {
    bindFakeHostResolver([
        'good.example' => ['93.184.216.34'],
        'private.example' => ['10.0.0.5'],
        'mixed.example' => ['93.184.216.34', '10.0.0.5'],
        'empty.example' => [],
        'many.example' => array_map(fn (int $i): string => "93.184.216.{$i}", range(1, 30)),
        'dup.example' => ['93.184.216.34', '93.184.216.34', '93.184.216.34'],
        'aaaa.example' => ['2606:4700:4700::1111'],
    ]);
});

function validate(string $url, int $redirectHop = 0): ResolvedImportTarget
{
    return app(UrlImportValidator::class)->validate($url, $redirectHop);
}

// --- Scheme / URL syntax -----------------------------------------------

it('allows a normal https url and returns the resolved target', function () {
    $target = validate('https://good.example/page?x=1');

    expect($target->url)->toBe('https://good.example/page?x=1')
        ->and($target->scheme)->toBe('https')
        ->and($target->host)->toBe('good.example')
        ->and($target->port)->toBe(443)
        ->and($target->ip)->toBe('93.184.216.34');
});

it('rejects http scheme, since only https is currently configured as allowed', function () {
    expect(fn () => validate('http://good.example/image.jpg'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects file scheme', function () {
    expect(fn () => validate('file:///etc/passwd'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects gopher scheme', function () {
    expect(fn () => validate('gopher://good.example/1'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects ftp scheme', function () {
    expect(fn () => validate('ftp://good.example/file.jpg'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects data scheme', function () {
    expect(fn () => validate('data:text/plain;base64,aGVsbG8='))->toThrow(UnsafeImportUrlException::class);
});

it('rejects php scheme', function () {
    expect(fn () => validate('php://filter/read=convert.base64-encode/resource=index.php'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects dict scheme', function () {
    expect(fn () => validate('dict://good.example:2628/'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects smb scheme', function () {
    expect(fn () => validate('smb://good.example/share'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects an arbitrary unknown scheme', function () {
    expect(fn () => validate('weird-scheme://good.example/'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects a url with no scheme at all', function () {
    expect(fn () => validate('good.example/page'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects a url with no host', function () {
    expect(fn () => validate('https:///no-host'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects userinfo in the form user:pass@host', function () {
    expect(fn () => validate('https://user:pass@good.example/'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects userinfo in the bare token@host form', function () {
    expect(fn () => validate('https://token@good.example/'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects a url containing a CR/LF byte', function () {
    expect(fn () => validate("https://good.example/\r\nX-Injected: 1"))->toThrow(UnsafeImportUrlException::class);
});

it('rejects a url containing a NUL byte', function () {
    expect(fn () => validate("https://good.example/\0evil"))->toThrow(UnsafeImportUrlException::class);
});

it('rejects a malformed IPv6 literal (unbalanced bracket)', function () {
    expect(fn () => validate('https://[::1/path'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects a bracketed host whose contents are not a valid IPv6 address', function () {
    expect(fn () => validate('https://[not-an-ipv6]/path'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects an entirely invalid url', function () {
    expect(fn () => validate('not-a-url'))->toThrow(UnsafeImportUrlException::class);
});

// --- Port policy ---------------------------------------------------------

it('rejects a forbidden port', function () {
    expect(fn () => validate('https://good.example:8080/'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects common internal service ports', function () {
    foreach ([22, 25, 3306, 5432, 6379, 9200, 11211] as $port) {
        expect(fn () => validate("https://good.example:{$port}/"))->toThrow(UnsafeImportUrlException::class);
    }
});

it('allows the default https port both implicitly and explicitly', function () {
    $implicit = validate('https://good.example/');
    $explicit = validate('https://good.example:443/');

    expect($implicit->port)->toBe(443)
        ->and($explicit->port)->toBe(443);
});

it('allows an explicitly configured non-default allowed port', function () {
    config(['import.allowed_ports' => [443, 8443]]);

    $target = validate('https://good.example:8443/');

    expect($target->port)->toBe(8443);
});

// --- IPv4 address policy --------------------------------------------------

$blockedIpv4 = [
    '127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254',
    '100.64.0.1', '0.0.0.0', '198.18.0.1', '224.0.0.1',
    '192.0.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1', '240.0.0.1',
];

foreach ($blockedIpv4 as $ip) {
    it("rejects the blocked IPv4 literal {$ip}", function () use ($ip) {
        expect(fn () => validate("https://{$ip}/path"))->toThrow(UnsafeImportUrlException::class);
    });
}

it('rejects the cloud metadata address specifically', function () {
    expect(fn () => validate('https://169.254.169.254/latest/meta-data'))->toThrow(UnsafeImportUrlException::class);
});

it('allows a genuinely public IPv4 literal', function () {
    $target = validate('https://93.184.216.34/');

    expect($target->ip)->toBe('93.184.216.34');
});

// --- Ambiguous numeric hosts ------------------------------------------------

$ambiguousHosts = ['127.1', '2130706433', '0177.0.0.1', '0x7f000001'];

foreach ($ambiguousHosts as $host) {
    it("rejects the ambiguous numeric host {$host}", function () use ($host) {
        expect(fn () => validate("https://{$host}/"))->toThrow(UnsafeImportUrlException::class);
    });
}

// --- IPv6 address policy --------------------------------------------------

$blockedIpv6 = [
    '::1', '::', 'fc00::1', 'fd12::1', 'fe80::1', 'ff02::1',
    '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254',
];

foreach ($blockedIpv6 as $ip) {
    it("rejects the blocked IPv6 literal [{$ip}]", function () use ($ip) {
        expect(fn () => validate("https://[{$ip}]/path"))->toThrow(UnsafeImportUrlException::class);
    });
}

it('allows a genuinely public IPv6 literal', function () {
    $target = validate('https://[2606:4700:4700::1111]/');

    expect($target->ip)->toBe('2606:4700:4700::1111');
});

it('rejects localhost by name', function () {
    expect(fn () => validate('https://localhost/test'))->toThrow(UnsafeImportUrlException::class);
});

// --- DNS answer semantics (resolver mocked, never live) --------------------

it('rejects a hostname with zero DNS answers', function () {
    expect(fn () => validate('https://empty.example/'))->toThrow(UnsafeImportUrlException::class);
});

it('allows a hostname with exactly one public answer', function () {
    $target = validate('https://good.example/');

    expect($target->ip)->toBe('93.184.216.34');
});

it('allows a hostname whose only AAAA answer is public', function () {
    $target = validate('https://aaaa.example/');

    expect($target->ip)->toBe('2606:4700:4700::1111');
});

it('rejects a hostname that resolves only to a private address', function () {
    expect(fn () => validate('https://private.example/'))->toThrow(UnsafeImportUrlException::class);
});

it('rejects a hostname with a mixed public and private answer set entirely', function () {
    expect(fn () => validate('https://mixed.example/'))->toThrow(UnsafeImportUrlException::class);
});

it('normalizes duplicate DNS answers to a single validated IP', function () {
    $target = validate('https://dup.example/');

    expect($target->ip)->toBe('93.184.216.34');
});

it('handles a pathologically large answer set without error, deterministically picking the first', function () {
    $target = validate('https://many.example/');

    expect($target->ip)->toBe('93.184.216.1');
});

it('rejects an unresolvable hostname', function () {
    expect(fn () => validate('https://this-does-not-exist-xyz.example/'))->toThrow(UnsafeImportUrlException::class);
});

// --- Fragment / canonical URL ------------------------------------------------

it('strips the fragment from the resolved target url', function () {
    $target = validate('https://good.example/page#section');

    expect($target->url)->toBe('https://good.example/page');
});
