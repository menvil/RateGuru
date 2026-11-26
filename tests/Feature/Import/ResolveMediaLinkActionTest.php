<?php

use App\Actions\Import\ResolveMediaLinkAction;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Import\MediaLinkRefusedException;
use App\Models\User;
use App\Services\Media\MediaToolResult;

/*
 * What a link given to the upload form comes to, decided outside the form:
 * a picture now, a video once the post exists, the post's source, or a
 * refusal its author can act on — every link counted first.
 */

beforeEach(function () {
    bindFakeHostResolver(['www.redgifs.com' => ['104.18.0.1'], 'videos.example' => ['93.184.216.40']]);
    Http::preventStrayRequests();
});

it('takes a picture\'s link as a picture, with no source of its own', function () {
    $resolved = app(ResolveMediaLinkAction::class)->handle(User::factory()->create(), 'https://videos.example/photo.jpg');

    expect([$resolved->imageUrl, $resolved->videoUrl, $resolved->source])->toBe(['https://videos.example/photo.jpg', null, null]);
});

it('takes a video file\'s link as a video to download, never asking yt-dlp', function () {
    $runner = bindRecordingMediaToolRunner();

    $resolved = app(ResolveMediaLinkAction::class)->handle(User::factory()->create(), 'https://videos.example/clip.mp4?token=secret');

    expect([$resolved->imageUrl, $resolved->videoUrl])->toBe([null, 'https://videos.example/clip.mp4?token=secret'])
        ->and($runner->calls)->toBe([]);
});

it('refuses a page on a site of videos alone that gives none, rather than posting its poster', function () {
    config(['import.ytdlp.sites' => ['redgifs.com' => 'RedGifs']]);
    bindRecordingMediaToolRunner([new MediaToolResult(1, '', 'ERROR: Unable to extract')]);

    expect(fn () => app(ResolveMediaLinkAction::class)->handle(User::factory()->create(), 'https://www.redgifs.com/watch/gone'))
        ->toThrow(MediaLinkRefusedException::class, __('import.errors.fetch_failed'));
});

it('counts every link against its author\'s link imports before anything is fetched', function () {
    config(['rate_limits.link_import.max_attempts' => 1]);
    $author = User::factory()->create();

    app(ResolveMediaLinkAction::class)->handle($author, 'https://videos.example/photo.jpg');

    expect(fn () => app(ResolveMediaLinkAction::class)->handle($author, 'https://videos.example/photo.jpg'))->toThrow(RateLimitExceededException::class);
});
