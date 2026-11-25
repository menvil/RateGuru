<?php

use App\Models\Post;
use Tests\Browser\Support\ImageFixtures;

/*
 * A post's video in a real browser: it plays by itself, muted, while it is on
 * screen; its sound comes on only when asked, and then every other video's
 * goes off.
 */

afterEach(function () {
    ImageFixtures::cleanup();
});

/** How the feed's videos are doing: playing or paused, muted or not. */
function feedVideoStates(mixed $page): array
{
    return $page->script(<<<'JS'
        Array.from(document.querySelectorAll('[data-testid="post-card-image-open"]'))
            .map((button) => button.closest('[data-testid="post-video"]')?.querySelector('video'))
            .filter(Boolean)
            .map((video) => ({ playing: !video.paused, muted: video.muted }))
    JS);
}

it('plays a video by itself, muted, and turns one video\'s sound on and every other one\'s off', function () {
    Post::factory()->published()->create(['title' => 'First Clip', 'image_asset_id' => ImageFixtures::writeVideo()->id]);
    Post::factory()->published()->create(['title' => 'Second Clip', 'image_asset_id' => ImageFixtures::writeVideo()->id]);

    // Tall enough for both cards to be well on screen.
    $page = visit(route('feed'))->resize(1440, 2400)->assertSee('First Clip');
    $page->wait(2);

    $states = feedVideoStates($page);

    expect($states)->toHaveCount(2)
        ->and(array_column($states, 'muted'))->toBe([true, true])
        ->and(array_column($states, 'playing'))->toContain(true);

    $page->script('document.querySelectorAll(\'[data-testid="post-video-sound"]\')[0].click()');
    $page->wait(0.5);

    expect(array_column(feedVideoStates($page), 'muted'))->toBe([false, true]);

    $page->script('document.querySelectorAll(\'[data-testid="post-video-sound"]\')[1].click()');
    $page->wait(0.5);

    expect(array_column(feedVideoStates($page), 'muted'))->toBe([true, false]);
});

it('pauses a video scrolled out of view', function () {
    Post::factory()->published()->create(['title' => 'Scrolled Clip', 'image_asset_id' => ImageFixtures::writeVideo()->id]);

    $page = visit(route('feed'))->resize(1440, 1600)->assertSee('Scrolled Clip');
    $page->wait(2);

    expect(feedVideoStates($page)[0]['playing'])->toBeTrue();

    $page->script('document.querySelector(\'[data-testid="post-video"]\').style.marginTop = "5000px"; window.scrollTo(0, 0);');
    $page->wait(1);

    expect(feedVideoStates($page)[0]['playing'])->toBeFalse();
});
