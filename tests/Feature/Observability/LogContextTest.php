<?php

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
