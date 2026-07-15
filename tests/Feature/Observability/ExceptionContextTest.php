<?php

use App\Models\User;
use App\Support\Observability\ExceptionContextBuilder;

it('adds request id to exception context', function () {
    $context = app(ExceptionContextBuilder::class)->build(new RuntimeException('Test'));

    expect($context)->toHaveKey('request_id');
});

it('adds app_env to exception context', function () {
    $context = app(ExceptionContextBuilder::class)->build(new RuntimeException('Test'));

    expect($context)->toHaveKey('app_env');
});

it('adds exception_class to context', function () {
    $context = app(ExceptionContextBuilder::class)->build(new RuntimeException('Test'));

    expect($context['exception_class'])->toBe(RuntimeException::class);
});

it('adds user id when authenticated', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('feed'));

    $context = app(ExceptionContextBuilder::class)->build(new RuntimeException('Test'));

    expect($context['user_id'])->toBe($user->id);
});

it('does not include password or token in exception context', function () {
    $context = app(ExceptionContextBuilder::class)->build(new RuntimeException('Test'));

    expect($context)->not->toHaveKey('password');
    expect($context)->not->toHaveKey('token');
    expect($context)->not->toHaveKey('_token');
});
