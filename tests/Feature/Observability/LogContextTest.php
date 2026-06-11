<?php

use App\Models\Post;
use App\Models\User;
use App\Support\Observability\LogContext;

it('builds base log context', function () {
    $context = app(LogContext::class)->base();

    expect($context)->toHaveKey('request_id');
    expect($context)->toHaveKey('app_env');
});

it('includes authenticated user id in log context', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('feed'));

    $context = app(LogContext::class)->base();

    expect($context['user_id'])->toBe($user->id);
});

it('includes locale in log context', function () {
    $context = app(LogContext::class)->base();

    expect($context)->toHaveKey('locale');
});

it('does not include sensitive fields in base context', function () {
    $context = app(LogContext::class)->base();

    expect($context)->not->toHaveKey('password');
    expect($context)->not->toHaveKey('token');
    expect($context)->not->toHaveKey('_token');
    expect($context)->not->toHaveKey('remember_token');
});

it('builds post context', function () {
    $post = Post::factory()->published()->create();

    $context = app(LogContext::class)->forPost($post);

    expect($context)->toHaveKey('post_id');
    expect($context['post_id'])->toBe($post->id);
});

it('builds user context', function () {
    $user = User::factory()->create();

    $context = app(LogContext::class)->forUser($user);

    expect($context)->toHaveKey('user_id');
    expect($context['user_id'])->toBe($user->id);
    expect($context)->not->toHaveKey('password');
});

it('builds import context', function () {
    $context = app(LogContext::class)->forImport('https://example.com', 'opengraph');

    expect($context)->toHaveKey('source_host');
    expect($context)->toHaveKey('provider');
});

it('merges multiple contexts', function () {
    $a = ['key_a' => 1];
    $b = ['key_b' => 2];

    $merged = app(LogContext::class)->merge($a, $b);

    expect($merged)->toHaveKey('key_a');
    expect($merged)->toHaveKey('key_b');
});
