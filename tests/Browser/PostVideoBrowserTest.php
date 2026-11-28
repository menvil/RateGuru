<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use Tests\Browser\Support\ImageFixtures;

/*
 * A post's video in a real browser: nothing plays, nor is fetched, by itself;
 * its play button starts it, muted, and one starting pauses the rest. Its
 * sound comes on only when asked, and then every other video's goes off.
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
            .map((video) => ({ playing: !video.paused, muted: video.muted, fetched: !!video.getAttribute('src') }))
    JS);
}

it('plays nothing by itself, plays the one asked, muted, one at a time, and turns one video\'s sound on and every other one\'s off', function () {
    Post::factory()->published()->create(['title' => 'First Clip', 'image_asset_id' => ImageFixtures::writeVideo()->id]);
    Post::factory()->published()->create(['title' => 'Second Clip', 'image_asset_id' => ImageFixtures::writeVideo()->id]);

    // Tall enough for both cards to be well on screen.
    $page = visit(route('feed'))->resize(1440, 2400)->assertSee('First Clip');
    $feedVideo = fn (int $index): string => "Array.from(document.querySelectorAll('[data-testid=\"post-card-image-open\"]')).map((b) => b.closest('[data-testid=\"post-video\"]').querySelector('video'))[{$index}]";
    $playing = "Array.from(document.querySelectorAll('[data-testid=\"post-card-image-open\"]')).filter((b) => ! b.closest('[data-testid=\"post-video\"]').querySelector('video').paused).length";
    // The feed cards' play buttons, in order: the viewers' players use the browser's controls.
    $play = fn (int $index): string => ':nth-match([data-testid="post-video-play"], '.($index + 1).')';

    // On screen, both with their posters — and still nothing plays or is fetched.
    waitForScript($page, '!! '.$feedVideo(0).".getAttribute('poster') && !! ".$feedVideo(1).".getAttribute('poster')");
    expect(feedVideoStates($page))->toBe([['playing' => false, 'muted' => true, 'fetched' => false], ['playing' => false, 'muted' => true, 'fetched' => false]]);

    // Its play button starts it, muted; another's pauses it.
    $page->click($play(0));
    waitForScript($page, '! '.$feedVideo(0).'.paused');
    expect(array_column(feedVideoStates($page), 'muted'))->toBe([true, true]);

    $page->click($play(1));
    waitForScript($page, '! '.$feedVideo(1).'.paused');
    waitForScript($page, $feedVideo(0).'.paused');
    waitForScript($page, $playing, 1);

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

    $page->click('[data-testid="post-video-play"]');
    waitForScript($page, "! {$video}.paused");

    $page->script('document.querySelector(\'[data-testid="post-video"]\').style.marginTop = "5000px"; window.scrollTo(0, 0);');

    waitForScript($page, "{$video}.paused");
});

it('moves a keyboard user\'s focus from the play button to the video, whose Space then pauses it', function () {
    Post::factory()->published()->create(['title' => 'Keyboard Clip', 'image_asset_id' => ImageFixtures::writeVideo()->id]);

    $page = visit(route('feed'))->resize(1440, 1600)->assertSee('Keyboard Clip');
    $video = "document.querySelector('[data-testid=\"post-video\"] video')";

    $page->script('document.querySelector(\'[data-testid="post-video-play"]\').focus()');
    $page->keys(':focus', 'Enter');
    waitForScript($page, "! {$video}.paused");
    waitForScript($page, "document.activeElement === {$video}");

    $page->keys(':focus', 'Space');
    waitForScript($page, "{$video}.paused");
});

it('plays a feed\'s video by itself, muted, one at a time, when Project settings say so', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['autoplay_feed_videos' => true]]);
    Post::factory()->published()->create(['title' => 'First Clip', 'image_asset_id' => ImageFixtures::writeVideo()->id]);
    Post::factory()->published()->create(['title' => 'Second Clip', 'image_asset_id' => ImageFixtures::writeVideo()->id]);

    $page = visit(route('feed'))->resize(1440, 2400)->assertSee('First Clip');
    $playing = "Array.from(document.querySelectorAll('[data-testid=\"post-card-image-open\"]')).filter((b) => ! b.closest('[data-testid=\"post-video\"]').querySelector('video').paused).length";

    waitForScript($page, $playing, 1);
    expect(array_column(feedVideoStates($page), 'muted'))->toBe([true, true]);
});
