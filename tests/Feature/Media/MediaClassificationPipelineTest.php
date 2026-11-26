<?php

use App\Console\Commands\MediaClassifyCommand;
use App\Enums\ClassificationMode;
use App\Enums\ClassificationStatus;
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
use App\Services\Media\Classification\MediaClassifierRegistry;
use App\Services\Media\Classification\QueueMediaClassification;
use App\Services\Media\MediaToolResult;
use App\Services\Media\MediaToolRunner;
use App\Services\Media\StoredMedia;
use App\Support\Media\MediaAssetCreator;
use App\Support\Media\WorkerTemporaryDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;

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

/** ffmpeg as a runner that writes a stand-in JPEG wherever it is asked to — failing the runs numbered in $failing. */
function framesWrittenByFfmpeg(array $failing = []): object
{
    $runner = new class($failing) implements MediaToolRunner
    {
        /** @var list<list<string>> */
        public array $calls = [];

        public function __construct(private array $failing) {}

        public function run(MediaTool $tool, array $arguments, int $timeoutSeconds): MediaToolResult
        {
            $this->calls[] = $arguments;

            if (in_array(count($this->calls) - 1, $this->failing, true)) {
                return new MediaToolResult(1, '', 'Invalid data');
            }

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

    $input = app(ClassificationFrameSampler::class)->sample($asset, $this->scratch);

    expect($input->frames)->toHaveCount(1)
        ->and($input->frames[0]->label)->toBe('still')
        ->and(getimagesize($input->frames[0]->path))->toMatchArray([0 => 1280, 1 => 720, 'mime' => 'image/jpeg']);
});

it('shows a video or GIF as JPEG frames at those moments, through the input whitelists, no larger than 1280, each a short run', function () {
    $runner = framesWrittenByFfmpeg();

    $input = app(ClassificationFrameSampler::class)->sample(readyVideoAsset(5000, MediaType::Animation), $this->scratch);

    expect(array_map(fn ($frame): array => [$frame->atMs, $frame->label], $input->frames))->toBe([[500, 'poster'], [1250, 'frame'], [3750, 'frame']])
        ->and($runner->calls)->toHaveCount(3);

    $first = $runner->calls[0];
    expect($first[array_search('-ss', $first, true) + 1])->toBe('0.500')
        ->and($first)->toContain('-protocol_whitelist', '-format_whitelist', 'mjpeg')
        ->and($first[array_search('-vf', $first, true) + 1])->toBe("scale='min(1280,iw)':'min(1280,ih)':force_original_aspect_ratio=decrease")
        // Its own time limit, not a transcode's: two threads' worth of 30 s.
        ->and($first[array_search('-timelimit', $first, true) + 1])->toBe('60');
});

it('leaves out a frame ffmpeg cannot take, and shows the rest', function () {
    framesWrittenByFfmpeg(failing: [1]);

    $input = app(ClassificationFrameSampler::class)->sample(readyVideoAsset(5000), $this->scratch);

    expect(array_map(fn ($frame): int => $frame->atMs, $input->frames))->toBe([500, 3750]);
});

it('says so when no frame could be taken, or the file cannot be read or decoded', function () {
    bindRecordingMediaToolRunner();
    expect(fn () => app(ClassificationFrameSampler::class)->sample(readyVideoAsset(), $this->scratch))
        ->toThrow(MediaClassificationFailedException::class, 'No frame');

    $missing = MediaAsset::factory()->create(['path' => 'posts/gone.jpg']);
    expect(fn () => app(ClassificationFrameSampler::class)->sample($missing, $this->scratch))
        ->toThrow(MediaClassificationFailedException::class, 'could not be read');

    $garbled = MediaAsset::factory()->create(['path' => 'posts/garbled.jpg']);
    Storage::disk('public')->put('posts/garbled.jpg', 'not a picture');
    expect(fn () => app(ClassificationFrameSampler::class)->sample($garbled, $this->scratch))
        ->toThrow(MediaClassificationFailedException::class, 'could not be decoded');
});

it('takes real frames with the real ffmpeg', function () {
    $dir = requireRealFfmpeg();

    try {
        makeWithRealFfmpeg(['-f', 'lavfi', '-i', 'testsrc=duration=5:size=1920x1080:rate=10', '-pix_fmt', 'yuv420p', $dir.'/clip.mp4']);
        $asset = readyVideoAsset(5000);
        Storage::disk('public')->put('posts/clip.mp4', (string) file_get_contents($dir.'/clip.mp4'));

        $input = app(ClassificationFrameSampler::class)->sample($asset, $this->scratch);

        expect($input->frames)->toHaveCount(3);
        foreach ($input->frames as $frame) {
            expect(getimagesize($frame->path))->toMatchArray([0 => 1280, 1 => 720, 'mime' => 'image/jpeg']);
        }
    } finally {
        removeScratchDir($dir);
    }
});

it('shows a post\'s media to every configured classifier once, recording each answer, its frames gone with the run', function () {
    $shadow = standInClassifier(['breast_exposed' => 0.2], name: 'trial');
    $active = standInClassifier(['breast_exposed' => 0.9], name: 'decider');
    configureClassifiers(['test.trial' => [$shadow, 'shadow'], 'test.decider' => [$active, 'active']]);
    framesWrittenByFfmpeg();
    $asset = readyVideoAsset(5000);

    app()->call([new ClassifyMediaAssetJob($asset->id), 'handle']);
    // Asked again — a retry, a second queueing — it asks no one twice.
    app()->call([new ClassifyMediaAssetJob($asset->id), 'handle']);

    expect(MediaClassification::query()->orderBy('id')->get(['classifier', 'mode'])->map(fn ($row): array => [$row->classifier, $row->mode])->all())
        ->toBe([['trial', ClassificationMode::Shadow], ['decider', ClassificationMode::Active]])
        ->and($shadow->shown)->toBe([3])
        ->and($active->shown)->toBe([3])
        // In this worker's own directory while shown, and gone with the run.
        ->and(array_values(array_unique($shadow->files)))->toBe([true])
        ->and(dirname(array_key_first($shadow->files), 2))->toBe(WorkerTemporaryDirectory::path('classification'))
        ->and(array_filter(array_keys($shadow->files), 'file_exists'))->toBe([])
        ->and(is_dir(dirname(array_key_first($shadow->files))))->toBeFalse();
});

it('asks the rest when one classifier fails, and records media nothing can be taken from for each, its directory gone', function () {
    Exceptions::fake();
    $broken = standInClassifier(failure: new RuntimeException('bug'), name: 'broken');
    $working = standInClassifier(['sex_toy' => 0.3], name: 'working');
    configureClassifiers(['test.broken' => [$broken, 'shadow'], 'test.working' => [$working, 'shadow']]);
    framesWrittenByFfmpeg();
    $asset = readyVideoAsset();

    app()->call([new ClassifyMediaAssetJob($asset->id), 'handle']);
    expect(MediaClassification::query()->orderBy('id')->get()->map(fn ($row): array => [$row->classifier, $row->status])->all())
        ->toBe([['broken', ClassificationStatus::Failed], ['working', ClassificationStatus::Succeeded]]);

    MediaClassification::query()->delete();
    $root = WorkerTemporaryDirectory::path('classification');
    $before = glob($root.'/*') ?: [];
    bindRecordingMediaToolRunner();

    app()->call([new ClassifyMediaAssetJob($asset->id), 'handle']);

    expect(MediaClassification::query()->orderBy('id')->pluck('error', 'classifier')->all())
        ->toBe(['broken' => 'No frame could be taken from the media.', 'working' => 'No frame could be taken from the media.'])
        ->and(glob($root.'/*') ?: [])->toBe($before);
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

it('queues a post\'s ready media, and nothing at all with no classifier configured', function () {
    Queue::fake();
    $photo = MediaAsset::factory()->create();

    app(QueueMediaClassification::class)->ready($photo);
    Queue::assertNothingPushed();

    configureClassifiers(['test.only' => [standInClassifier(), 'shadow']]);
    app(QueueMediaClassification::class)->ready($photo);
    app(QueueMediaClassification::class)->ready(MediaAsset::factory()->create(['kind' => MediaKind::Avatar]));
    app(QueueMediaClassification::class)->ready(MediaAsset::factory()->create(['status' => MediaStatus::Processing]));

    Queue::assertPushed(ClassifyMediaAssetJob::class, 1);
    Queue::assertPushed(ClassifyMediaAssetJob::class, fn (ClassifyMediaAssetJob $job): bool => $job->mediaAssetId === $photo->id);
});

it('queues nothing until the transaction that made the media ready commits, and nothing if it rolls back', function () {
    Queue::fake();
    configureClassifiers(['test.only' => [standInClassifier(), 'shadow']]);

    DB::transaction(function () {
        app(QueueMediaClassification::class)->ready(MediaAsset::factory()->create());
        Queue::assertNothingPushed();
    });
    Queue::assertPushed(ClassifyMediaAssetJob::class, 1);

    try {
        DB::transaction(function () {
            app(QueueMediaClassification::class)->ready(MediaAsset::factory()->create());

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    Queue::assertPushed(ClassifyMediaAssetJob::class, 1);
});

it('queues one job per asset at a time, however often it is asked', function () {
    Queue::fake();
    configureClassifiers(['test.only' => [standInClassifier(), 'shadow']]);
    $photo = MediaAsset::factory()->create();

    app(QueueMediaClassification::class)->ready($photo);
    app(QueueMediaClassification::class)->ready($photo);

    Queue::assertPushed(ClassifyMediaAssetJob::class, 1);
});

it('queues an uploaded post picture as it is stored, never an avatar', function () {
    Queue::fake();
    configureClassifiers(['test.only' => [standInClassifier(), 'shadow']]);
    $stored = fn (string $path): StoredMedia => new StoredMedia(disk: 'public', path: $path, originalFilename: 'x.jpg', mimeType: 'image/jpeg', extension: 'jpg', byteSize: 10, width: 10, height: 10);

    $photo = app(MediaAssetCreator::class)->create($stored('posts/x.jpg'), MediaKind::PostImage, User::factory()->create());
    app(MediaAssetCreator::class)->create($stored('avatars/x.jpg'), MediaKind::Avatar, User::factory()->create());

    Queue::assertPushed(ClassifyMediaAssetJob::class, 1);
    Queue::assertPushed(ClassifyMediaAssetJob::class, fn (ClassifyMediaAssetJob $job): bool => $job->mediaAssetId === $photo->id);
});

it('never fails an upload over its classification: a queue that will not take it is reported, a configuration it cannot use never read', function () {
    Exceptions::fake();
    configureClassifiers(['test.only' => [standInClassifier(), 'shadow']]);
    Queue::swap(new class(app()) extends QueueFake
    {
        public function push($job, $data = '', $queue = null)
        {
            throw new RuntimeException('The media queue is down.');
        }

        public function later($delay, $job, $data = '', $queue = null)
        {
            throw new RuntimeException('The media queue is down.');
        }
    });
    $stored = fn (string $path): StoredMedia => new StoredMedia(disk: 'public', path: $path, originalFilename: 'x.jpg', mimeType: 'image/jpeg', extension: 'jpg', byteSize: 10, width: 10, height: 10);

    $asset = DB::transaction(fn () => app(MediaAssetCreator::class)->create($stored('posts/y.jpg'), MediaKind::PostImage, User::factory()->create()));

    expect($asset->exists)->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The media queue is down.');

    // An entry naming no mode: the upload never reads it, only the job does.
    config(['media.classification.classifiers' => [['classifier' => 'test.only']]]);
    expect(app(MediaAssetCreator::class)->create($stored('posts/z.jpg'), MediaKind::PostImage, User::factory()->create())->exists)->toBeTrue();
});

it('queues the media stored before, newest first, that a configured classifier has not answered for, spread out', function () {
    Queue::fake();
    configureClassifiers(['test.trial' => [standInClassifier(name: 'trial'), 'shadow']]);
    $answered = MediaAsset::factory()->create();
    MediaClassification::factory()->create(['media_asset_id' => $answered->id, 'classifier' => 'trial', 'model_version' => 'v1']);
    $failed = MediaAsset::factory()->create();
    MediaClassification::factory()->create(['media_asset_id' => $failed->id, 'classifier' => 'trial', 'model_version' => 'v1', 'status' => ClassificationStatus::Failed]);
    $older = MediaAsset::factory()->create();
    $newer = MediaAsset::factory()->create();
    MediaAsset::factory()->create(['kind' => MediaKind::Avatar]);

    $this->artisan('media:classify', ['--limit' => 1])->expectsOutputToContain('Queued 1 asset(s)')->assertSuccessful();
    Queue::assertPushed(ClassifyMediaAssetJob::class, fn (ClassifyMediaAssetJob $job): bool => $job->mediaAssetId === $newer->id);

    // Each run's jobs anew: the unique locks of the last are let go.
    Queue::fake();
    Cache::flush();
    $this->artisan('media:classify')->assertSuccessful();
    $jobs = collect(Queue::pushed(ClassifyMediaAssetJob::class));

    // One a classifier failed on counts as answered, unless asked for again.
    expect($jobs->pluck('mediaAssetId')->all())->toBe([$newer->id, $older->id])
        ->and($jobs->last()->delay->getTimestamp() - $jobs->first()->delay->getTimestamp())->toBe(MediaClassifyCommand::SPACING_SECONDS);

    Queue::fake();
    Cache::flush();
    $this->artisan('media:classify', ['--retry-failed' => true])->assertSuccessful();
    expect(collect(Queue::pushed(ClassifyMediaAssetJob::class))->pluck('mediaAssetId')->all())->toBe([$newer->id, $older->id, $failed->id]);
});

it('says there is nothing to queue with no classifier configured, and refuses a configuration it cannot use', function () {
    $this->artisan('media:classify')->expectsOutputToContain('No classifier is configured')->assertSuccessful();

    config(['media.classification.classifiers' => [['classifier' => 'stdClass', 'mode' => 'shadow']]]);
    expect(fn () => app(MediaClassifierRegistry::class)->configured())->toThrow(InvalidArgumentException::class);

    config(['media.classification.classifiers' => [['classifier' => 'x', 'mode' => 'benchmark']]]);
    expect(fn () => app(MediaClassifierRegistry::class)->configured())->toThrow(InvalidArgumentException::class);
});

it('never asks a classifier again for a failure it recorded, on the job\'s own retry neither, unless --retry-failed asks for one recorded before', function () {
    Exceptions::fake();
    $broken = standInClassifier(failure: new RuntimeException('bug'), name: 'broken');
    $working = standInClassifier(['sex_toy' => 0.3], name: 'working');
    configureClassifiers(['test.broken' => [$broken, 'shadow'], 'test.working' => [$working, 'shadow']]);
    framesWrittenByFfmpeg();
    $asset = readyVideoAsset();
    $job = new ClassifyMediaAssetJob($asset->id);

    app()->call([$job, 'handle']);
    // The other's answer lost to the database, the job is retried: the
    // failure recorded is the broken one's answer.
    MediaClassification::query()->where('classifier', 'working')->delete();
    app()->call([$job, 'handle']);

    expect($broken->shown)->toHaveCount(1)
        ->and($working->shown)->toHaveCount(2);

    // Asked again for what failed before — in the very same second — once:
    // its own retry asks no more.
    $retry = new ClassifyMediaAssetJob($asset->id, (int) MediaClassification::query()->max('id'));
    app()->call([$retry, 'handle']);
    app()->call([$retry, 'handle']);

    expect($broken->shown)->toHaveCount(2)
        ->and($working->shown)->toHaveCount(2);
});

it('hands its jobs the retry it was asked for, and holds each one\'s lock through its delay', function () {
    $this->freezeTime();
    Queue::fake();
    configureClassifiers(['test.trial' => [standInClassifier(name: 'trial'), 'shadow']]);
    MediaAsset::factory()->count(2)->create();

    $this->artisan('media:classify')->assertSuccessful();
    $jobs = collect(Queue::pushed(ClassifyMediaAssetJob::class));

    expect($jobs->pluck('retryFailuresThrough')->all())->toBe([null, null])
        ->and($jobs->map(fn (ClassifyMediaAssetJob $job): int => $job->uniqueFor())->all())->toBe([960, 960 + MediaClassifyCommand::SPACING_SECONDS]);

    Queue::fake();
    Cache::flush();
    $this->artisan('media:classify', ['--retry-failed' => true])->assertSuccessful();

    expect(collect(Queue::pushed(ClassifyMediaAssetJob::class))->pluck('retryFailuresThrough')->unique()->all())->toBe([(int) MediaClassification::query()->max('id')]);
});

it('stops asking for frames once its time is up, and shows the ones it took', function () {
    $runner = new class implements MediaToolRunner
    {
        public int $calls = 0;

        public function run(MediaTool $tool, array $arguments, int $timeoutSeconds): MediaToolResult
        {
            $this->calls++;
            file_put_contents((string) end($arguments), 'jpeg');
            // Each frame takes past half the budget: a third is never asked for.
            Carbon::setTestNow(now()->addSeconds(intdiv(ClassificationFrameSampler::SAMPLING_BUDGET_SECONDS, 2) + 1));

            return new MediaToolResult(0, '', '');
        }
    };
    app()->instance(MediaToolRunner::class, $runner);

    $input = app(ClassificationFrameSampler::class)->sample(readyVideoAsset(20000), $this->scratch);

    expect($input->frames)->toHaveCount(2)
        ->and($runner->calls)->toBe(2);
});

it('never runs two jobs for one asset at once, and one queued after the other\'s lock expired asks no one again', function () {
    $classifier = standInClassifier(name: 'only');
    configureClassifiers(['test.only' => [$classifier, 'shadow']]);
    framesWrittenByFfmpeg();
    $asset = readyVideoAsset();
    $job = fn (): ClassifyMediaAssetJob => (new ClassifyMediaAssetJob($asset->id))->onConnection('sync');

    // A second job, queued once the first one's unique lock had expired,
    // arrives while the first still runs: it is dropped, not run alongside.
    $running = Cache::lock($job()->middleware()[0]->getLockKey($job()), 960);
    $running->get();
    dispatch($job());
    expect($classifier->shown)->toBe([]);
    $running->release();
    Cache::flush();

    // The first runs; another arriving after it finds the answer there.
    dispatch($job());
    Cache::flush();
    dispatch($job());

    expect($classifier->shown)->toHaveCount(1);
});
