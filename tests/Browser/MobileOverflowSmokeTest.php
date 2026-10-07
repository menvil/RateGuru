<?php

use App\Models\Post;
use App\Models\User;
use Tests\Browser\Support\MobileViewports;

use function Pest\Laravel\actingAs;

it('does not horizontally overflow on mobile feed at 375px', function () {
    Post::factory()->published()->create([
        'title' => 'Mobile Overflow Smoke Post',
    ]);

    $page = visit(route('feed'))->resize(...MobileViewports::SMALL_MOBILE);

    waitForViewportSize($page, ...MobileViewports::SMALL_MOBILE);

    $overflow = $page->script('document.documentElement.scrollWidth - window.innerWidth');

    expect($overflow)->toBeLessThanOrEqual(1);
});

it('does not horizontally overflow on mobile post show at 375px', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Mobile Overflow Post Show',
    ]);

    $page = visit(route('posts.show', $post))->resize(...MobileViewports::SMALL_MOBILE);

    waitForViewportSize($page, ...MobileViewports::SMALL_MOBILE);

    $overflow = $page->script('document.documentElement.scrollWidth - window.innerWidth');

    expect($overflow)->toBeLessThanOrEqual(1);
});

it('does not horizontally overflow on login at 375px', function () {
    $page = visit(route('login'))->resize(...MobileViewports::SMALL_MOBILE);

    waitForViewportSize($page, ...MobileViewports::SMALL_MOBILE);

    $overflow = $page->script('document.documentElement.scrollWidth - window.innerWidth');

    expect($overflow)->toBeLessThanOrEqual(1);
});

it('does not horizontally overflow on register at 375px', function () {
    $page = visit(route('register'))->resize(...MobileViewports::SMALL_MOBILE);

    waitForViewportSize($page, ...MobileViewports::SMALL_MOBILE);

    $overflow = $page->script('document.documentElement.scrollWidth - window.innerWidth');

    expect($overflow)->toBeLessThanOrEqual(1);
});

it('does not horizontally overflow on profile at 375px', function () {
    $user = User::factory()->create(['username' => 'overflow_smoke_user']);

    actingAs($user);

    $page = visit(route('profile.show', $user->username))->resize(...MobileViewports::SMALL_MOBILE);

    waitForViewportSize($page, ...MobileViewports::SMALL_MOBILE);

    $overflow = $page->script('document.documentElement.scrollWidth - window.innerWidth');

    expect($overflow)->toBeLessThanOrEqual(1);
});
