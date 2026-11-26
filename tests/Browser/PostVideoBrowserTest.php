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

it('plays one video at a time by itself, muted, and turns one video\'s sound on and every other one\'s off', function () {
    Post::factory()->published()->create(['title' => 'First Clip', 'image_asset_id' => ImageFixtures::writeVideo()->id]);
    Post::factory()->published()->create(['title' => 'Second Clip', 'image_asset_id' => ImageFixtures::writeVideo()->id]);

    // Tall enough for both cards to be well on screen.
    $page = visit(route('feed'))->resize(1440, 2400)->assertSee('First Clip');
    $feedVideo = fn (int $index): string => "Array.from(document.querySelectorAll('[data-testid=\"post-card-image-open\"]')).map((b) => b.closest('[data-testid=\"post-video\"]').querySelector('video'))[{$index}]";
    $playing = "Array.from(document.querySelectorAll('[data-testid=\"post-card-image-open\"]')).filter((b) => ! b.closest('[data-testid=\"post-video\"]').querySelector('video').paused).length";

    // One plays at a time, muted.
    waitForScript($page, $playing, 1);
    expect(array_column(feedVideoStates($page), 'muted'))->toBe([true, true]);

    // The one asked plays, with its sound, and it alone.
    $page->script('document.querySelectorAll(\'[data-testid="post-video-sound"]\')[0].click()');
    waitForScript($page, $feedVideo(0).'.muted', false);
    waitForScript($page, '! '.$feedVideo(0).'.paused');
    waitForScript($page, $playing, 1);
    expect(array_column(feedVideoStates($page), 'muted'))->toBe([false, true]);

    $page->script('document.querySelectorAll(\'[data-testid="post-video-sound"]\')[1].click()');
    waitForScript($page, $feedVideo(1).'.muted', false);
    waitForScript($page, '! '.$feedVideo(1).'.paused');
    waitForScript($page, $feedVideo(0).'.muted');
    waitForScript($page, $playing, 1);
});

it('pauses a video scrolled out of view', function () {
    Post::factory()->published()->create(['title' => 'Scrolled Clip', 'image_asset_id' => ImageFixtures::writeVideo()->id]);

    $page = visit(route('feed'))->resize(1440, 1600)->assertSee('Scrolled Clip');
    $video = "document.querySelector('[data-testid=\"post-video\"] video')";

    waitForScript($page, "! {$video}.paused");

    $page->script('document.querySelector(\'[data-testid="post-video"]\').style.marginTop = "5000px"; window.scrollTo(0, 0);');

    waitForScript($page, "{$video}.paused");
});
