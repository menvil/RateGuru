<?php

use App\Support\Auth\AuthReturnUrl;

it('keeps a local path, query string included', function (string $path) {
    expect(AuthReturnUrl::sanitize($path))->toBe($path)
        ->and(AuthReturnUrl::resolve($path))->toBe($path);
})->with([
    '/',
    '/posts/123',
    '/feed?sort=top&page=2',
    '/?sort=top&page=2',
    '/u/chef_ivan',
    '/posts/123?utm_source=newsletter#comments',
    '/search?q=%D0%BF%D0%B8%D1%86%D1%86%D0%B0',
    '/posts/1?next=https%3A%2F%2Fexample.com',
]);

it('rejects everything that could leave the application', function (mixed $candidate) {
    expect(AuthReturnUrl::sanitize($candidate))->toBeNull()
        ->and(AuthReturnUrl::resolve($candidate))->toBe('/');
})->with([
    'absolute https' => ['https://evil.example'],
    'absolute http' => ['http://evil.example'],
    'protocol relative' => ['//evil.example'],
    'protocol relative with path' => ['//evil.example/posts/1'],
    'backslash pair' => ['\\\\evil.example'],
    'slash then backslash' => ['/\\evil.example'],
    'backslash inside the path' => ['/posts\\..\\evil'],
    'javascript scheme' => ['javascript:alert(1)'],
    'data scheme' => ['data:text/html,<script>alert(1)</script>'],
    'scheme without slashes' => ['mailto:someone@example.com'],
    'relative path' => ['posts/123'],
    'bare host' => ['evil.example'],
    'header injection' => ["/posts/1\r\nLocation: https://evil.example"],
    'newline' => ["/posts/1\nfoo"],
    'tab' => ["/posts/\t1"],
    'null byte' => ["/posts/1\0"],
    'space' => ['/posts/1 2'],
    'leading space' => [' /posts/1'],
    'empty string' => [''],
    'null' => [null],
    'array' => [['/posts/1']],
    'integer' => [42],
    'overlong' => ['/'.str_repeat('a', 2048)],
]);

it('uses the fallback it is given for an unusable value', function () {
    expect(AuthReturnUrl::resolve('https://evil.example', '/dashboard'))->toBe('/dashboard')
        ->and(AuthReturnUrl::resolve('/posts/5', '/dashboard'))->toBe('/posts/5');
});
