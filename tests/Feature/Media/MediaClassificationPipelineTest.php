<?php

use App\Enums\ClassificationMode;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaTool;
use App\Enums\MediaType;
use App\Jobs\ClassifyMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\MediaClassification;
use App\Models\User;
use App\Services\Media\Classification\ClassificationFrameSampler;
use App\Services\Media\Classification\MediaClassificationFailedException;
use App\Services\Media\Classification\MediaClassificationRecorder;
use App\Services\Media\Classification\MediaClassifierRegistry;
use App\Services\Media\Classification\QueueMediaClassification;
use App\Services\Media\MediaToolResult;
use App\Services\Media\MediaToolRunner;
use App\Services\Media\StoredMedia;
use App\Support\Media\MediaAssetCreator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * How a post's media reaches the configured content classifiers: the frames
 * they are shown, the job that shows them, and when it is queued.
 */

beforeEach(function () {
    Storage::fake('public');
    $this->scratch = makeScratchDir('classification-test');
});

afterEach(function () {
    removeScratchDir($this->scratch);
});

/** ffmpeg as a runner that writes a stand-in JPEG wherever it is asked to. */
function framesWrittenByFfmpeg(): object
{
    $runner = new class implements MediaToolRunner
    {
        /** @var list<list<string>> */
        public array $calls = [];

        public function run(MediaTool $tool, array $arguments, int $timeoutSeconds): MediaToolResult
        {
            $this->calls[] = $arguments;
            file_put_contents((string) end($arguments), 'jpeg');

            return new MediaToolResult(0, '', '');
        }
    };
    app()->instance(MediaToolRunner::class, $runner);

    return $runner;
}

/** A ready video asset whose master is on the public disk. */
function readyVideoAsset(int $durationMs = 6000, MediaType $type = MediaType::Video): MediaAsset
{
    $asset = MediaAsset::factory()->create(['media_type' => $type, 'duration_ms' => $durationMs, 'path' => 'posts/clip.mp4', 'mime_type' => 'video/mp4']);
    Storage::disk('public')->put('posts/clip.mp4', 'mp4');

    return $asset;
}

/** Classifiers bound and configured under $bindings => mode. */
function configureClassifiers(array $classifiers): void
{
    $entries = [];

    foreach ($classifiers as $binding => [$classifier, $mode]) {
        app()->instance($binding, $classifier);
        $entries[] = ['classifier' => $binding, 'mode' => $mode];
    }

    config(['media.classification.classifiers' => $entries]);
}

it('takes the poster\'s moment and the middle of every two-second stretch, at most thirty', function () {
    expect(ClassificationFrameSampler::times(5000))->toBe([[500, 'poster'], [1250, 'frame'], [3750, 'frame']])
        ->and(ClassificationFrameSampler::times(900))->toBe([[90, 'poster'], [450, 'frame']])
        ->and(ClassificationFrameSampler::times(null))->toBe([[0, 'poster']]);

    $long = ClassificationFrameSampler::times(90_000);
    expect($long)->toHaveCount(31)
        ->and($long[0])->toBe([3000, 'poster'])
        ->and($long[1])->toBe([1500, 'frame'])
        ->and($long[30])->toBe([88_500, 'frame']);
});

it('shows a photo as one JPEG of its master, no larger than the feed\'s own picture', function () {
    $asset = MediaAsset::factory()->create(['path' => 'posts/big.jpg']);
    Storage::disk('public')->put('posts/big.jpg', jpegMarkerBytes(2560, 1440));
    $directory = $this->scratch;

    $input = app(ClassificationFrameSampler::class)->sample($asset, $directory);

    expect($input->frames)->toHaveCount(1)
        ->and($input->frames[0]->label)->toBe('still')
        ->and(getimagesize($input->frames[0]->path))->toMatchArray([0 => 1280, 1 => 720, 'mime' => 'image/jpeg']);
});

it('shows a video or GIF as JPEG frames at those moments, through the input whitelists, no larger than 1280', function () {
    $runner = framesWrittenByFfmpeg();

    $input = app(ClassificationFrameSampler::class)->sample(readyVideoAsset(5000, MediaType::Animation), $this->scratch);

    expect(array_map(fn ($frame): array => [$frame->atMs, $frame->label], $input->frames))->toBe([[500, 'poster'], [1250, 'frame'], [3750, 'frame']])
        ->and($runner->calls)->toHaveCount(3);

    $first = $runner->calls[0];
    expect($first[array_search('-ss', $first, true) + 1])->toBe('0.500')
        ->and($first)->toContain('-protocol_whitelist', '-format_whitelist', 'mjpeg')
        ->and($first[array_search('-vf', $first, true) + 1])->toBe("scale='min(1280,iw)':'min(1280,ih)':force_original_aspect_ratio=decrease");
});

it('says so when no frame could be taken', function () {
    bindRecordingMediaToolRunner();

    app(ClassificationFrameSampler::class)->sample(readyVideoAsset(), $this->scratch);
})->throws(MediaClassificationFailedException::class);

it('takes real frames with the real ffmpeg', function () {
    $dir = requireRealFfmpeg();
    makeWithRealFfmpeg(['-f', 'lavfi', '-i', 'testsrc=duration=5:size=1920x1080:rate=10', '-pix_fmt', 'yuv420p', $dir.'/clip.mp4']);
    $asset = readyVideoAsset(5000);
    Storage::disk('public')->put('posts/clip.mp4', (string) file_get_contents($dir.'/clip.mp4'));

    $input = app(ClassificationFrameSampler::class)->sample($asset, $this->scratch);

    expect($input->frames)->toHaveCount(3);
    foreach ($input->frames as $frame) {
        expect(getimagesize($frame->path))->toMatchArray([0 => 1280, 1 => 720, 'mime' => 'image/jpeg']);
    }
});

it('shows a post\'s media to every configured classifier once, recording each answer, and leaves no frame behind', function () {
    $shadow = standInClassifier(['breast_exposed' => 0.2], name: 'trial');
    $active = standInClassifier(['breast_exposed' => 0.9], name: 'decider');
    configureClassifiers(['test.trial' => [$shadow, 'shadow'], 'test.decider' => [$active, 'active']]);
    framesWrittenByFfmpeg();
    $asset = readyVideoAsset(5000);
    $before = glob(sys_get_temp_dir().'/rateguru-classification/*') ?: [];

    (new ClassifyMediaAssetJob($asset->id))->handle(app(MediaClassifierRegistry::class), app(ClassificationFrameSampler::class), app(MediaClassificationRecorder::class));
    // Asked again — a retry, a second queueing — it asks no one twice.
    (new ClassifyMediaAssetJob($asset->id))->handle(app(MediaClassifierRegistry::class), app(ClassificationFrameSampler::class), app(MediaClassificationRecorder::class));

    expect(MediaClassification::query()->orderBy('id')->get(['classifier', 'mode'])->map(fn ($row): array => [$row->classifier, $row->mode])->all())
        ->toBe([['trial', ClassificationMode::Shadow], ['decider', ClassificationMode::Active]])
        ->and($shadow->shown)->toBe([3])
        ->and($active->shown)->toBe([3])
        ->and(glob(sys_get_temp_dir().'/rateguru-classification/*') ?: [])->toBe($before);
});

it('leaves media alone that is not a post\'s, or not ready', function () {
    $classifier = standInClassifier();
    configureClassifiers(['test.only' => [$classifier, 'shadow']]);
    $avatar = MediaAsset::factory()->create(['kind' => MediaKind::Avatar]);
    $processing = MediaAsset::factory()->create(['status' => MediaStatus::Processing]);

    foreach ([$avatar, $processing] as $asset) {
        app()->call([new ClassifyMediaAssetJob($asset->id), 'handle']);
    }

    expect($classifier->shown)->toBe([])
        ->and(MediaClassification::query()->count())->toBe(0);
});

it('queues a post\'s media once it is ready, after the commit, and nothing at all with no classifier configured', function () {
    Queue::fake();
    $photo = MediaAsset::factory()->create();

    app(QueueMediaClassification::class)->ready($photo);
    Queue::assertNothingPushed();

    configureClassifiers(['test.only' => [standInClassifier(), 'shadow']]);
    app(QueueMediaClassification::class)->ready($photo);
    app(QueueMediaClassification::class)->ready(MediaAsset::factory()->create(['kind' => MediaKind::Avatar]));
    app(QueueMediaClassification::class)->ready(MediaAsset::factory()->create(['status' => MediaStatus::Processing]));

    Queue::assertPushed(ClassifyMediaAssetJob::class, 1);
    Queue::assertPushed(ClassifyMediaAssetJob::class, fn (ClassifyMediaAssetJob $job): bool => $job->mediaAssetId === $photo->id && $job->afterCommit === true);
});

it('queues an uploaded post picture as it is stored', function () {
    Queue::fake();
    configureClassifiers(['test.only' => [standInClassifier(), 'shadow']]);
    $user = User::factory()->create();

    $asset = app(MediaAssetCreator::class)->create(new StoredMedia(
        disk: 'public', path: 'posts/x.jpg', originalFilename: 'x.jpg', mimeType: 'image/jpeg', extension: 'jpg', byteSize: 10, width: 10, height: 10,
    ), MediaKind::PostImage, $user);

    Queue::assertPushed(ClassifyMediaAssetJob::class, fn (ClassifyMediaAssetJob $job): bool => $job->mediaAssetId === $asset->id);
});

it('queues a video or GIF once its processing leaves it ready', function () {
    Queue::fake();
    configureClassifiers(['test.only' => [standInClassifier(), 'shadow']]);
    $video = readyVideoAsset();

    app()->call([new App\Jobs\ProcessVideoAssetJob($video->id), 'handle']);

    Queue::assertPushed(ClassifyMediaAssetJob::class, fn (ClassifyMediaAssetJob $job): bool => $job->mediaAssetId === $video->id);
});

it('queues the media stored before, newest first, that a configured classifier has not answered for', function () {
    Queue::fake();
    $classifier = standInClassifier(name: 'trial');
    configureClassifiers(['test.trial' => [$classifier, 'shadow']]);
    $answered = MediaAsset::factory()->create();
    MediaClassification::factory()->create(['media_asset_id' => $answered->id, 'classifier' => 'trial', 'model_version' => 'v1']);
    $older = MediaAsset::factory()->create();
    $newer = MediaAsset::factory()->create();
    MediaAsset::factory()->create(['kind' => MediaKind::Avatar]);

    $this->artisan('media:classify', ['--limit' => 1])->expectsOutputToContain('Queued 1 asset(s)')->assertSuccessful();
    Queue::assertPushed(ClassifyMediaAssetJob::class, fn (ClassifyMediaAssetJob $job): bool => $job->mediaAssetId === $newer->id);

    Queue::fake();
    $this->artisan('media:classify')->assertSuccessful();
    expect(collect(Queue::pushed(ClassifyMediaAssetJob::class))->pluck('mediaAssetId')->sort()->values()->all())->toBe([$older->id, $newer->id]);
});

it('says there is nothing to queue with no classifier configured, and refuses a configuration it cannot use', function () {
    $this->artisan('media:classify')->expectsOutputToContain('No classifier is configured')->assertSuccessful();

    config(['media.classification.classifiers' => [['classifier' => 'stdClass', 'mode' => 'shadow']]]);
    expect(fn () => app(MediaClassifierRegistry::class)->configured())->toThrow(InvalidArgumentException::class);

    config(['media.classification.classifiers' => [['classifier' => 'x', 'mode' => 'benchmark']]]);
    expect(fn () => app(MediaClassifierRegistry::class)->configured())->toThrow(InvalidArgumentException::class);
});
