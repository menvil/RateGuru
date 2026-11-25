<?php

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\MediaVariantName;
use App\Enums\MediaVisibility;
use App\Enums\PostImageContext;
use App\Livewire\Posts\PostShow;
use App\Models\Post;
use App\Support\Media\PostImagePresenter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
});

/** A video post with every poster variant and both renditions. */
function fullVideoPost(array $attributes = []): Post
{
    return videoPostWithPosterVariants(
        $attributes,
        MediaVariantName::PostFeed640,
        MediaVariantName::PostFeed1280,
        MediaVariantName::PostDetail1920,
        MediaVariantName::OpenGraph,
        MediaVariantName::Video720,
        MediaVariantName::Video480,
    );
}

it('plays the 720 rendition on the feed and the post\'s pages, and the master in the viewer, under its poster', function () {
    $post = fullVideoPost();
    $presenter = app(PostImagePresenter::class);
    $rendition = fn (MediaVariantName $name): string => Storage::disk('public')->url($post->imageAsset->variants->firstWhere('name', $name)->path);

    foreach ([PostImageContext::Feed, PostImageContext::Drawer, PostImageContext::Standalone] as $context) {
        $video = $presenter->video($post, $context);

        expect($video->src)->toBe($rendition(MediaVariantName::Video720))
            ->and([$video->width, $video->height])->toBe([720, 1280])
            ->and($video->poster)->toBe($presenter->responsive($post, $context)->src)
            ->and($video->hasAudio)->toBeTrue();
    }

    expect($presenter->video($post, PostImageContext::Fullscreen)->src)->toBe(Storage::disk('public')->url($post->imageAsset->path));
});

it('plays nothing for a photo, for media still processing, for a private file', function () {
    $presenter = app(PostImagePresenter::class);
    $photo = Post::factory()->published()->withImage()->create()->load('imageAsset.variants');
    $processing = fullVideoPost(['status' => MediaStatus::Processing]);
    $private = fullVideoPost(['visibility' => MediaVisibility::Private]);

    foreach ([$photo, $processing, $private] as $post) {
        expect($presenter->video($post, PostImageContext::Feed))->toBeNull();
    }
});

it('plays a video muted in the feed, with a sound button only when it has sound, and a button to open it fullscreen', function () {
    fullVideoPost();
    fullVideoPost(['media_type' => MediaType::Animation, 'has_audio' => false]);

    $html = $this->get(route('feed'))->assertOk()->getContent();

    expect(substr_count($html, 'data-testid="post-video"'))->toBe(4) // two cards, each with its fullscreen viewer
        ->and($html)->toContain('x-data="rgPostVideo()"')
        ->and($html)->toMatch('/<video\s[^>]*src="[^"]+video_720\.mp4"[^>]*poster="[^"]+"[^>]*muted\s+playsinline\s+loop/s')
        ->and(substr_count($html, 'data-testid="post-video-sound"'))->toBe(1)
        ->and($html)->toContain('aria-label="'.e(__('ui.post.video_open')).'"')
        ->and($html)->not->toContain('video_720.mp4" alt=');
});

it('gives the post page and the viewer the browser\'s controls, still muted', function () {
    $post = fullVideoPost();

    $html = Livewire::test(PostShow::class, ['post' => $post])->html();

    expect($html)->toMatch('/<video\s[^>]*muted\s+playsinline\s+loop\s+preload="auto"\s+controls/s')
        ->and($html)->not->toContain('data-testid="post-video-sound"');
});

it('still shows a photo post as a picture', function () {
    Post::factory()->published()->withImage()->create();

    $html = $this->get(route('feed'))->assertOk()->getContent();

    expect($html)->not->toContain('data-testid="post-video"')
        ->and($html)->toContain('data-testid="post-card-image-open"');
});
