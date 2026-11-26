<?php

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\MediaVisibility;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Jobs\ProcessVideoAssetJob;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\Exceptions\VideoTooLongException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
});

it('keeps a video post as a draft, its original private, until the media queue has processed it', function () {
    $runner = bindRecordingMediaToolRunner([acceptableFfprobeResult()]);
    $author = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($author, new CreatePostData(title: 'A clip', video: fakeVideoUpload()));

    $asset = MediaAsset::query()->findOrFail($post->image_asset_id);

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->published_at)->toBeNull()
        ->and($asset->status)->toBe(MediaStatus::Processing)
        ->and($asset->media_type)->toBe(MediaType::Video)
        ->and($asset->visibility)->toBe(MediaVisibility::Private)
        ->and($asset->disk)->toBe('local')
        ->and($asset->path)->toStartWith('media/originals/')
        ->and($asset->path)->toEndWith('.mp4')
        ->and([$asset->width, $asset->height, $asset->duration_ms, $asset->has_audio])->toBe([1080, 1920, 15_000, true])
        ->and($runner->calls)->toHaveCount(1);
    Storage::disk('local')->assertExists($asset->path);
    Storage::disk('public')->assertMissing($asset->path);

    Queue::assertPushed(ProcessVideoAssetJob::class, fn (ProcessVideoAssetJob $job): bool => $job->mediaAssetId === $asset->id);
    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
    // Nobody hears of a post nobody can see yet.
    Queue::assertNotPushed(NotifyFollowersAboutNewPostJob::class);
});

it('takes an animated GIF as a silent animation', function () {
    bindRecordingMediaToolRunner([acceptableFfprobeResult(gif: true)]);

    $post = app(CreatePostAction::class)->handle(User::factory()->create(), new CreatePostData(title: 'A loop', video: fakeVideoUpload('loop.gif')));
    $asset = MediaAsset::query()->findOrFail($post->image_asset_id);

    expect($asset->media_type)->toBe(MediaType::Animation)
        ->and($asset->has_audio)->toBeFalse()
        ->and($asset->path)->toEndWith('.gif')
        ->and($asset->mime_type)->toBe('image/gif');
});

it('stores nothing and creates no post for a video the upload policy refuses', function () {
    bindRecordingMediaToolRunner([acceptableFfprobeResult(['duration' => '120.000000'])]);

    expect(fn () => app(CreatePostAction::class)->handle(User::factory()->create(), new CreatePostData(title: 'Too long', video: fakeVideoUpload())))
        ->toThrow(VideoTooLongException::class);

    expect(Post::query()->count())->toBe(0)
        ->and(MediaAsset::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('refuses another video while the author already has as many processing as allowed, before reading the file', function () {
    config(['uploads.videos.max_processing_per_author' => 2]);
    $author = User::factory()->create();
    MediaAsset::factory()->count(2)->create([
        'owner_user_id' => $author->id,
        'media_type' => MediaType::Video,
        'status' => MediaStatus::Processing,
    ]);
    $runner = bindRecordingMediaToolRunner([acceptableFfprobeResult()]);

    try {
        app(CreatePostAction::class)->handle($author, new CreatePostData(title: 'One more', video: fakeVideoUpload()));
        $this->fail('A third video was accepted.');
    } catch (CannotCreatePostException $exception) {
        expect($exception->isWaitingForVideos())->toBeTrue();
    }

    expect($runner->calls)->toBe([])
        ->and(Post::query()->count())->toBe(0);
});

it('takes one picture, video or stored image per post, never two', function () {
    expect(fn () => app(CreatePostAction::class)->handle(User::factory()->create(), new CreatePostData(
        title: 'Both',
        image: UploadedFile::fake()->image('photo.jpg'),
        video: fakeVideoUpload(),
    )))->toThrow(InvalidArgumentException::class);
});

it('counts a GIF still processing towards the author\'s limit, as a video', function () {
    config(['uploads.videos.max_processing_per_author' => 2, 'uploads.videos.processing_window_minutes' => 180]);
    $author = User::factory()->create();
    // One video and one GIF still processing: the limit, only if the GIF counts.
    MediaAsset::factory()->create(['owner_user_id' => $author->id, 'media_type' => MediaType::Video, 'status' => MediaStatus::Processing]);
    MediaAsset::factory()->create(['owner_user_id' => $author->id, 'media_type' => MediaType::Animation, 'status' => MediaStatus::Processing]);
    bindRecordingMediaToolRunner([acceptableFfprobeResult()]);

    expect(fn () => app(CreatePostAction::class)->handle($author, new CreatePostData(title: 'Third', video: fakeVideoUpload())))
        ->toThrow(CannotCreatePostException::class);
});

it('counts only the author\'s own uploads still within the processing window', function () {
    config(['uploads.videos.max_processing_per_author' => 2, 'uploads.videos.processing_window_minutes' => 180]);
    $author = User::factory()->create();
    MediaAsset::factory()->create(['owner_user_id' => $author->id, 'media_type' => MediaType::Video, 'status' => MediaStatus::Processing]);
    // Not counted: processed, another author's, or past every retry its job could make.
    MediaAsset::factory()->create(['owner_user_id' => $author->id, 'media_type' => MediaType::Video, 'status' => MediaStatus::Ready]);
    MediaAsset::factory()->create(['owner_user_id' => User::factory()->create()->id, 'media_type' => MediaType::Video, 'status' => MediaStatus::Processing]);
    MediaAsset::factory()->create(['owner_user_id' => $author->id, 'media_type' => MediaType::Animation, 'status' => MediaStatus::Processing, 'created_at' => now()->subHours(4)]);
    bindRecordingMediaToolRunner([acceptableFfprobeResult()]);

    $post = app(CreatePostAction::class)->handle($author, new CreatePostData(title: 'Second', video: fakeVideoUpload()));

    expect($post->exists)->toBeTrue();
});

it('fails the asset at once when its job cannot be queued, so it never holds the author\'s place', function () {
    bindRecordingMediaToolRunner([acceptableFfprobeResult()]);
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('Redis is down'));

    expect(fn () => app(CreatePostAction::class)->handle(User::factory()->create(), new CreatePostData(title: 'Lost', video: fakeVideoUpload())))
        ->toThrow(RuntimeException::class, 'Redis is down');

    expect(MediaAsset::query()->sole()->status)->toBe(MediaStatus::Failed)
        ->and(Post::query()->sole()->status)->toBe(PostStatus::Draft);
});
