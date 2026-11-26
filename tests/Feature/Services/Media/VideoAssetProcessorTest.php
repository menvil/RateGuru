<?php

use App\Actions\Posts\FinalizePostMediaAction;
use App\Enums\MediaStatus;
use App\Enums\MediaTool;
use App\Enums\MediaType;
use App\Enums\MediaVariantName;
use App\Enums\MediaVisibility;
use App\Enums\PostStatus;
use App\Jobs\ClassifyMediaAssetJob;
use App\Jobs\ProcessVideoAssetJob;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\Classification\QueueMediaClassification;
use App\Services\Media\Exceptions\MediaToolInterruptedException;
use App\Services\Media\MediaToolResult;
use App\Services\Media\MediaToolRunner;
use App\Services\Media\VideoAssetProcessor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

afterEach(function () {
    if (isset($this->dir)) {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
});

/**
 * A video upload as the upload flow will leave it: the original on the
 * private disk under media/originals, the asset Processing and private.
 */
function processingVideoAsset(string $contents, string $extension, MediaType $type = MediaType::Video): MediaAsset
{
    $path = 'media/originals/2026/11/'.Str::uuid()->toString().'.'.$extension;
    Storage::disk('local')->put($path, $contents);

    return MediaAsset::factory()->dimensionless()->create([
        'disk' => 'local',
        'path' => $path,
        'mime_type' => $extension === 'gif' ? 'image/gif' : 'video/mp4',
        'extension' => $extension,
        'byte_size' => strlen($contents),
        'media_type' => $type,
        'status' => MediaStatus::Processing,
        'visibility' => MediaVisibility::Private,
        'created_at' => '2026-11-25 12:00:00',
    ]);
}

it('turns a landscape clip with sound into a public master, its renditions and the poster\'s image variants', function () {
    $this->dir = requireRealFfmpeg();
    makeWithRealFfmpeg([
        '-f', 'lavfi', '-i', 'testsrc=duration=3:size=1920x1080:rate=30',
        '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3',
        '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $this->dir.'/clip.mov',
    ]);
    $asset = processingVideoAsset((string) file_get_contents($this->dir.'/clip.mov'), 'mov');
    $original = $asset->path;

    app(VideoAssetProcessor::class)->process($asset);
    $asset->refresh()->load('variants');

    expect($asset->status)->toBe(MediaStatus::Ready)
        ->and($asset->processing_error)->toBeNull()
        ->and($asset->disk)->toBe('public')
        ->and($asset->path)->toBe('media/post-videos/2026/11/'.pathinfo($original, PATHINFO_FILENAME).'.mp4')
        ->and($asset->visibility)->toBe(MediaVisibility::Public)
        ->and([$asset->mime_type, $asset->extension])->toBe(['video/mp4', 'mp4'])
        ->and([$asset->width, $asset->height])->toBe([1920, 1080])
        ->and($asset->duration_ms)->toBeGreaterThan(2_900)->toBeLessThan(3_200)
        ->and($asset->has_audio)->toBeTrue()
        ->and($asset->media_type)->toBe(MediaType::Video)
        ->and($asset->metadata['original'])->toBe(['disk' => 'local', 'path' => $original])
        ->and(Storage::disk('public')->exists($asset->path))->toBeTrue();

    $variants = $asset->variants->keyBy(fn ($variant) => $variant->name->value);
    expect($variants->keys()->sort()->values()->all())->toBe(['open_graph', 'post_detail_1920', 'post_feed_1280', 'post_feed_640', 'video_480', 'video_720'])
        ->and([$variants['video_720']->width, $variants['video_720']->height])->toBe([1280, 720])
        ->and([$variants['video_480']->width, $variants['video_480']->height])->toBe([852, 480])
        ->and($variants['video_720']->duration_ms)->toBeGreaterThan(2_900)
        ->and($variants['post_feed_640']->mime_type)->toBe('image/webp')
        ->and($variants['open_graph']->mime_type)->toBe('image/jpeg');

    foreach ($variants as $variant) {
        expect(Storage::disk('public')->exists($variant->path))->toBeTrue($variant->name->value);
    }
});

it('keeps a portrait clip portrait, makes only the renditions below it, and leaves a silent clip silent', function () {
    $this->dir = requireRealFfmpeg();
    makeWithRealFfmpeg(['-f', 'lavfi', '-i', 'testsrc=duration=2:size=720x1280:rate=30', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $this->dir.'/vertical.mp4']);
    $asset = processingVideoAsset((string) file_get_contents($this->dir.'/vertical.mp4'), 'mp4');

    app(VideoAssetProcessor::class)->process($asset);
    $asset->refresh()->load('variants');
    $names = $asset->variants->map(fn ($variant) => $variant->name)->all();

    expect($asset->status)->toBe(MediaStatus::Ready)
        ->and([$asset->width, $asset->height])->toBe([720, 1280])
        ->and($asset->has_audio)->toBeFalse()
        ->and($names)->toContain(MediaVariantName::Video480)->not->toContain(MediaVariantName::Video720)
        // 720 wide is too narrow to crop a 1200x630 card from without upscaling.
        ->and($names)->not->toContain(MediaVariantName::OpenGraph);
});

it('turns an animated GIF into a silent looping animation', function () {
    $this->dir = requireRealFfmpeg();
    makeWithRealFfmpeg(['-f', 'lavfi', '-i', 'testsrc=duration=2:size=480x270:rate=10', $this->dir.'/loop.gif']);
    $asset = processingVideoAsset((string) file_get_contents($this->dir.'/loop.gif'), 'gif', MediaType::Animation);

    app(VideoAssetProcessor::class)->process($asset);
    $asset->refresh();

    expect($asset->status)->toBe(MediaStatus::Ready)
        ->and($asset->media_type)->toBe(MediaType::Animation)
        ->and($asset->has_audio)->toBeFalse()
        ->and($asset->mime_type)->toBe('video/mp4')
        ->and([$asset->width, $asset->height])->toBe([480, 270])
        ->and($asset->duration_ms)->toBeGreaterThan(1_500);
});

it('leaves a clip the policy refuses Failed, with the reason and nothing made', function () {
    $this->dir = requireRealFfmpeg();
    config(['uploads.videos.max_duration_seconds' => 1]);
    makeWithRealFfmpeg(['-f', 'lavfi', '-i', 'testsrc=duration=3:size=320x240:rate=25', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $this->dir.'/long.mp4']);
    $asset = processingVideoAsset((string) file_get_contents($this->dir.'/long.mp4'), 'mp4');

    app(VideoAssetProcessor::class)->process($asset);
    $asset->refresh();

    expect($asset->status)->toBe(MediaStatus::Failed)
        ->and($asset->processing_error)->toStartWith('The video plays for ')->toEndWith('exceeding the 1000 ms limit.')
        ->and($asset->variants()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        // The original stays where it was, for the lifecycle to remove.
        ->and(Storage::disk('local')->exists($asset->path))->toBeTrue();
});

it('marks a file ffprobe cannot read Failed without retrying, and never reads anything but the original', function () {
    $runner = bindRecordingMediaToolRunner([new MediaToolResult(1, '', 'Invalid data found when processing input')]);
    $asset = processingVideoAsset('not a video', 'mp4');

    app(VideoAssetProcessor::class)->process($asset);

    expect($asset->refresh()->status)->toBe(MediaStatus::Failed)
        ->and($asset->processing_error)->toBe('The file could not be read as a video in a supported format.')
        ->and($runner->calls)->toHaveCount(1);
});

it('reads the original from where the first attempt recorded it, after a partial run moved the asset\'s path', function () {
    bindRecordingMediaToolRunner([new MediaToolResult(1, '', '')]);
    $asset = processingVideoAsset('not a video', 'mp4');
    $original = $asset->path;
    // A first attempt recorded the original, wrote the master and moved the
    // asset onto it, then died; the master is gone with the failed host.
    $asset->forceFill([
        'metadata' => ['original' => ['disk' => 'local', 'path' => $original]],
        'disk' => 'public',
        'path' => 'media/post-videos/2026/11/vanished.mp4',
    ])->save();

    app(VideoAssetProcessor::class)->process($asset);

    // Reading the vanished master would have thrown; it read the original.
    expect($asset->refresh()->status)->toBe(MediaStatus::Failed);
});

it('leaves an asset alone unless it is a video still waiting to be processed', function () {
    $runner = bindRecordingMediaToolRunner();

    foreach ([
        MediaAsset::factory()->create(['status' => MediaStatus::Processing]),
        processingVideoAsset('x', 'mp4')->forceFill(['status' => MediaStatus::Ready]),
        processingVideoAsset('x', 'mp4')->forceFill(['status' => MediaStatus::Failed]),
    ] as $asset) {
        app(VideoAssetProcessor::class)->process($asset);
    }

    expect($runner->calls)->toBe([]);
});

it('stops an asset waiting once the job has failed for the last time, and leaves a finished one as it is', function () {
    $waiting = processingVideoAsset('x', 'mp4');
    $finished = processingVideoAsset('x', 'mp4');
    $finished->forceFill(['status' => MediaStatus::Ready])->save();

    (new ProcessVideoAssetJob($waiting->id))->failed(new RuntimeException('disk full'));
    (new ProcessVideoAssetJob($finished->id))->failed(new RuntimeException('late'));

    expect($waiting->refresh()->status)->toBe(MediaStatus::Failed)
        ->and($waiting->processing_error)->toBe('The video could not be processed.')
        ->and($finished->refresh()->status)->toBe(MediaStatus::Ready);
});

it('skips an asset that no longer exists', function () {
    $runner = bindRecordingMediaToolRunner();

    (new ProcessVideoAssetJob(999_999))->handle(app(VideoAssetProcessor::class), app(FinalizePostMediaAction::class), app(QueueMediaClassification::class));

    expect($runner->calls)->toBe([]);
});

it('sends the job back to wait when another run holds the asset, rather than acknowledging it while the asset still waits', function () {
    $runner = bindRecordingMediaToolRunner();
    $asset = processingVideoAsset('x', 'mp4');
    $held = Cache::store('database')->getStore()->lock("media-video-process:{$asset->id}", 60);
    expect($held->get())->toBeTrue();

    try {
        $job = (new ProcessVideoAssetJob($asset->id))->withFakeQueueInteractions();
        $job->handle(app(VideoAssetProcessor::class), app(FinalizePostMediaAction::class), app(QueueMediaClassification::class));

        $job->assertReleased(ProcessVideoAssetJob::BUSY_RETRY_SECONDS);
        expect($runner->calls)->toBe([])
            ->and($asset->refresh()->status)->toBe(MediaStatus::Processing);
    } finally {
        $held->release();
    }
});

it('leaves the asset waiting when a tool was interrupted, for the job to run it again', function () {
    app()->instance(MediaToolRunner::class, new class implements MediaToolRunner
    {
        public function run(MediaTool $tool, array $arguments, int $timeoutSeconds): MediaToolResult
        {
            throw MediaToolInterruptedException::bySignal($tool);
        }
    });
    $asset = processingVideoAsset('x', 'mp4');

    expect(fn () => app(VideoAssetProcessor::class)->process($asset))
        ->toThrow(MediaToolInterruptedException::class);

    expect($asset->refresh()->status)->toBe(MediaStatus::Processing)
        ->and($asset->processing_error)->toBeNull();
});

it('reads the asset again once it holds the lock, and leaves one another run settled meanwhile', function () {
    $runner = bindRecordingMediaToolRunner();
    $asset = processingVideoAsset('x', 'mp4');
    MediaAsset::query()->whereKey($asset->id)->update(['status' => MediaStatus::Ready]);

    // $asset still says Processing in memory.
    expect(app(VideoAssetProcessor::class)->process($asset))->toBeTrue()
        ->and($runner->calls)->toBe([])
        ->and($asset->status)->toBe(MediaStatus::Ready);
});

it('lets go of the lock when the asset cannot be read again under it', function () {
    bindRecordingMediaToolRunner();
    $asset = processingVideoAsset('x', 'mp4');
    // Gone between the job loading it and the lock: the read under the lock fails.
    MediaAsset::query()->whereKey($asset->id)->forceDelete();

    expect(fn () => app(VideoAssetProcessor::class)->process($asset))->toThrow(ModelNotFoundException::class);

    $lock = Cache::store('database')->getStore()->lock("media-video-process:{$asset->id}", 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('sweeps only a run\'s own stale work directory, never the import\'s that shares its prefix', function () {
    $import = sys_get_temp_dir().'/rateguru-video-import-'.posix_geteuid().'-sweep-test';
    $run = sys_get_temp_dir().'/rateguru-video-'.Str::uuid()->toString();
    mkdir($import);
    mkdir($run);
    touch($import, time() - 9000);
    touch($run, time() - 9000);

    try {
        (new ReflectionMethod(VideoAssetProcessor::class, 'clearStaleWorkDirectories'))->invoke(null);

        expect(is_dir($import))->toBeTrue()
            ->and(is_dir($run))->toBeFalse();
    } finally {
        @rmdir($import);
        @rmdir($run);
    }
});

it('queues the content classifiers once processing leaves a video ready, and never for one it failed', function () {
    $this->dir = requireRealFfmpeg();
    Queue::fake();
    app()->instance('test.classifier', standInClassifier());
    config(['media.classification.classifiers' => [['classifier' => 'test.classifier', 'mode' => 'shadow']]]);
    makeWithRealFfmpeg(['-f', 'lavfi', '-i', 'testsrc=duration=2:size=320x240:rate=25', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $this->dir.'/clip.mp4']);
    $ready = processingVideoAsset((string) file_get_contents($this->dir.'/clip.mp4'), 'mp4');
    $refused = processingVideoAsset('not a video', 'mp4');

    app()->call([new ProcessVideoAssetJob($ready->id), 'handle']);
    app()->call([new ProcessVideoAssetJob($refused->id), 'handle']);

    expect($ready->refresh()->status)->toBe(MediaStatus::Ready)
        ->and($refused->refresh()->status)->toBe(MediaStatus::Failed);
    Queue::assertPushed(ClassifyMediaAssetJob::class, 1);
    Queue::assertPushed(ClassifyMediaAssetJob::class, fn ($job): bool => $job->mediaAssetId === $ready->id);
});

it('queues the classifiers before the author\'s notice, so a notice that cannot be written leaves no ready video unclassified', function () {
    $this->dir = requireRealFfmpeg();
    Queue::fake();
    app()->instance('test.classifier', standInClassifier());
    config(['media.classification.classifiers' => [['classifier' => 'test.classifier', 'mode' => 'shadow']]]);
    makeWithRealFfmpeg(['-f', 'lavfi', '-i', 'testsrc=duration=2:size=320x240:rate=25', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $this->dir.'/clip.mp4']);
    $asset = processingVideoAsset((string) file_get_contents($this->dir.'/clip.mp4'), 'mp4');
    Post::factory()->create(['user_id' => User::factory()->create()->id, 'image_asset_id' => $asset->id, 'status' => PostStatus::Draft, 'published_at' => null]);
    DatabaseNotification::creating(fn () => throw new RuntimeException('the notice could not be written'));

    expect(fn () => app()->call([new ProcessVideoAssetJob($asset->id), 'handle']))->toThrow(RuntimeException::class, 'the notice could not be written');

    expect($asset->refresh()->status)->toBe(MediaStatus::Ready);
    Queue::assertPushed(ClassifyMediaAssetJob::class, fn ($job): bool => $job->mediaAssetId === $asset->id);
});
