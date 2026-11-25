<?php

use App\Actions\Import\ImportFromUrlAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\FinalizePostMediaAction;
use App\Data\Posts\CreatePostData;
use App\Enums\ImportProvider;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\PostStatus;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Jobs\ImportVideoAssetJob;
use App\Jobs\ProcessVideoAssetJob;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Notifications\VideoPostProcessedNotification;
use App\Services\Media\MediaAuditService;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportProviderDetector;
use App\Support\Import\ImportTransportResponse;
use App\Support\Import\SafeImportHttpClient;
use App\Support\Media\VideoUploadStorer;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    bindFakeHostResolver(['videos.example' => ['93.184.216.40'], 'intranet.example' => ['10.0.0.5']]);
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
    Notification::fake();
});

it('takes a link to a video file or a GIF for a video to fetch, and nothing else', function () {
    $detector = app(ImportProviderDetector::class);

    expect($detector->detect('https://videos.example/clip.mp4'))->toBe('direct_video')
        ->and($detector->detect('https://videos.example/loop.GIF'))->toBe('direct_video')
        ->and($detector->detect('https://videos.example/photo.jpg'))->toBe('direct_image');

    $preview = app(ImportFromUrlAction::class)->handle('https://videos.example/clip.mp4');

    expect($preview->provider)->toBe(ImportProvider::DirectVideo)
        ->and($preview->videoUrl)->toBe('https://videos.example/clip.mp4')
        ->and($preview->title)->toBe('clip')
        ->and($preview->imageUrl)->toBeNull();
});

it('downloads a video to a file through the same validated, pinned hops, and refuses a hop to a private address', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://videos.example/real.mp4'], 'moved'),
        new ImportTransportResponse(200, ['content-type' => 'video/mp4'], 'the video bytes'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);
    $path = sys_get_temp_dir().'/video-import-test-'.uniqid();

    try {
        $response = app(SafeImportHttpClient::class)->download('https://videos.example/clip.mp4', 1024, $path);

        expect(file_get_contents($path))->toBe('the video bytes')
            ->and($response->body)->toBe('')
            ->and($response->finalUrl)->toBe('https://videos.example/real.mp4')
            ->and($transport->calls[1]->ip)->toBe('93.184.216.40');
    } finally {
        @unlink($path);
    }

    app()->instance(ImportHttpTransport::class, new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://intranet.example/secret.mp4'], ''),
    ]));

    expect(fn () => app(SafeImportHttpClient::class)->download('https://videos.example/clip.mp4', 1024, $path))
        ->toThrow(UnsafeImportUrlException::class);
});

it('keeps a linked video post as a draft, its asset waiting for the download, and queues it', function () {
    $author = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($author, new CreatePostData(title: 'From a link', videoUrl: 'https://videos.example/clip.mp4'));
    $asset = MediaAsset::query()->findOrFail($post->image_asset_id);

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($asset->status)->toBe(MediaStatus::Processing)
        ->and($asset->metadata['import']['url'])->toBe('https://videos.example/clip.mp4')
        ->and($asset->disk)->toBe('local');
    Queue::assertPushed(ImportVideoAssetJob::class, fn (ImportVideoAssetJob $job): bool => $job->mediaAssetId === $asset->id);
    Queue::assertNotPushed(ProcessVideoAssetJob::class);
});

it('fetches the video, stores it as an upload\'s original and hands it to processing', function () {
    $author = User::factory()->create();
    $post = app(CreatePostAction::class)->handle($author, new CreatePostData(title: 'From a link', videoUrl: 'https://videos.example/clip.mp4'));
    app()->instance(ImportHttpTransport::class, new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'video/mp4'], 'the video bytes'),
    ]));
    bindRecordingMediaToolRunner([acceptableFfprobeResult()]);

    (new ImportVideoAssetJob((int) $post->image_asset_id))->handle(app(SafeImportHttpClient::class), app(VideoUploadStorer::class), app(FinalizePostMediaAction::class));

    $asset = MediaAsset::query()->findOrFail($post->image_asset_id);
    expect($asset->status)->toBe(MediaStatus::Processing)
        ->and($asset->media_type)->toBe(MediaType::Video)
        ->and([$asset->width, $asset->height, $asset->duration_ms])->toBe([1080, 1920, 15_000])
        ->and($asset->path)->toEndWith('.mp4')
        ->and($asset->metadata['import'])->toHaveKey('downloaded_at');
    Storage::disk('local')->assertExists($asset->path);
    Queue::assertPushed(ProcessVideoAssetJob::class, fn (ProcessVideoAssetJob $job): bool => $job->mediaAssetId === $asset->id);
});

it('fails the post\'s media and tells the author when the link cannot be fetched or the file is refused', function (Closure $script, Closure $probe) {
    $author = User::factory()->create();
    $post = app(CreatePostAction::class)->handle($author, new CreatePostData(title: 'From a link', videoUrl: 'https://videos.example/clip.mp4'));
    app()->instance(ImportHttpTransport::class, new ScriptedImportHttpTransport([$script()]));
    bindRecordingMediaToolRunner([$probe()]);

    (new ImportVideoAssetJob((int) $post->image_asset_id))->handle(app(SafeImportHttpClient::class), app(VideoUploadStorer::class), app(FinalizePostMediaAction::class));

    expect(MediaAsset::query()->findOrFail($post->image_asset_id)->status)->toBe(MediaStatus::Failed)
        ->and($post->refresh()->status)->toBe(PostStatus::Draft);
    Notification::assertSentTo($author, VideoPostProcessedNotification::class);
    Queue::assertNotPushed(ProcessVideoAssetJob::class);
})->with([
    'gone' => [fn () => new ImportTransportResponse(404, [], ''), fn () => acceptableFfprobeResult()],
    'too long' => [fn () => new ImportTransportResponse(200, [], 'bytes'), fn () => acceptableFfprobeResult(['duration' => '300.000000'])],
]);

it('counts a linked video against the author\'s processing limit', function () {
    config(['uploads.videos.max_processing_per_author' => 1]);
    $author = User::factory()->create();
    app(CreatePostAction::class)->handle($author, new CreatePostData(title: 'One', videoUrl: 'https://videos.example/a.mp4'));

    expect(fn () => app(CreatePostAction::class)->handle($author, new CreatePostData(title: 'Two', videoUrl: 'https://videos.example/b.mp4')))
        ->toThrow(CannotCreatePostException::class);

    expect(Post::query()->count())->toBe(1);
});

it('counts no missing master for a linked video before it was downloaded', function () {
    $post = app(CreatePostAction::class)->handle(User::factory()->create(), new CreatePostData(title: 'From a link', videoUrl: 'https://videos.example/clip.mp4'));

    expect(app(MediaAuditService::class)->run()->missingMasters)->toBe(0)
        ->and(MediaAsset::query()->findOrFail($post->image_asset_id)->status)->toBe(MediaStatus::Processing);
});
