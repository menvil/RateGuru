<?php

use App\Jobs\ProcessVideoAssetJob;
use App\Jobs\RunMediaAuditJob;
use App\Jobs\RunsOnMediaQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\Fixtures\QueuedWork\InheritedQueuedTask;

/**
 * Every class the application queues finishes inside the Redis queue's retry
 * window. `retry_after` is how long Redis keeps a reserved job to the worker
 * that took it; once it passes, the job is handed to the next worker that asks,
 * as if the first had died — while the first may still be running it. So each
 * class stops itself with a margin before that moment.
 *
 * The worker's own `--timeout` (120 s) is no help here: it is only the ceiling
 * for a class that declares nothing, and it lies past the window. A class must
 * declare its own timeout.
 */

/*
 * Classes allowed past the window, and why that is safe for each. A single
 * attempt is what makes it so: a copy handed out again is failed by the worker
 * before it runs (attempt 2 of 1), and with no failed() handler nothing about
 * the run still going is touched.
 */
$beyondTheWindow = [
    RunMediaAuditJob::class => 'a full media audit can outlast any window; one attempt, no failed() handler',
];

/*
 * Every concrete queued class under a PSR-4 root, found by what each class is
 * rather than by what its file says: a class queued only through its parent
 * never names the interface. Only class files are loaded — a file named after
 * no class (app/Support/helpers.php) is included at boot already, and
 * including it again would redeclare its functions.
 */
$queuedClassesIn = function (string $root, string $namespace): array {
    $classes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || ! ctype_upper($file->getFilename()[0])) {
            continue;
        }

        $class = $namespace.'\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));

        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
};

/*
 * The classes, by label, whose own timeout is missing, disables the timeout,
 * or lets them run past $latestStop. Zero or less disables it altogether: the
 * worker arms no alarm, and the run is never stopped.
 *
 * @param  array<string, object|class-string>  $classes
 * @return array<string, mixed>
 */
$outsideTheWindow = function (array $classes, int $latestStop): array {
    $outside = [];

    foreach ($classes as $label => $class) {
        $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

        if (! is_int($timeout) || $timeout < 1 || $timeout > $latestStop) {
            $outside[$label] = $timeout;
        }
    }

    return $outside;
};

/*
 * What, if anything, would let a second copy of an exempt class run or touch
 * the first. A retry deadline is read before the attempt count: while one is
 * unexpired the worker runs the copy whatever `tries` says.
 *
 * @return list<string>
 */
$exemptionProblems = function (object|string $class): array {
    $reflection = new ReflectionClass($class);
    $problems = [];

    if (($reflection->getDefaultProperties()['tries'] ?? null) !== 1) {
        $problems[] = 'more than one attempt';
    }

    if ($reflection->hasMethod('failed')) {
        $problems[] = 'a failed() handler';
    }

    if ($reflection->hasMethod('retryUntil') || $reflection->hasProperty('retryUntil')) {
        $problems[] = 'a retry deadline';
    }

    return $problems;
};

// The window as the repository ships it — what a target runs with when its
// environment leaves the value blank — and as configured here.
$latestStop = function (): int {
    preg_match("/EnvironmentValue::integer\(env\('REDIS_QUEUE_RETRY_AFTER'\), (\d+)\)/", (string) file_get_contents(config_path('queue.php')), $shipped);

    return min((int) $shipped[1], (int) config('queue.connections.redis.retry_after')) - 10;
};

it('finds the classes the application queues', function () use ($queuedClassesIn) {
    $classes = $queuedClassesIn(dirname(__DIR__, 3).'/app', 'App');

    // A scan that found nothing would pass every rule below.
    expect($classes)->toContain(RunMediaAuditJob::class)->and(count($classes))->toBeGreaterThan(5);
});

it('finds a class queued only through its parent, and leaves the abstract parent out', function () use ($queuedClassesIn) {
    expect($queuedClassesIn(dirname(__DIR__, 2).'/Fixtures/QueuedWork', 'Tests\Fixtures\QueuedWork'))
        ->toBe([InheritedQueuedTask::class]);
});

it('gives every queued class its own timeout, stopping it with a margin before Redis could hand it to another worker', function () use ($queuedClassesIn, $outsideTheWindow, $beyondTheWindow, $latestStop) {
    $classes = array_diff($queuedClassesIn(dirname(__DIR__, 3).'/app', 'App'), array_keys($beyondTheWindow));
    $media = array_values(array_filter($classes, fn (string $class): bool => is_subclass_of($class, RunsOnMediaQueue::class)));
    $default = array_values(array_diff($classes, $media));

    expect($media)->toContain(ProcessVideoAssetJob::class);

    expect($outsideTheWindow(array_combine($default, $default), $latestStop()))->toBe([])
        // A media job is held to the media connection's own, longer window.
        ->and($outsideTheWindow(array_combine($media, $media), (int) config('queue.connections.redis-media.retry_after') - 10))->toBe([]);
});

it('sends media jobs to a connection of their own on Redis, with a queue and a retry window of their own', function () {
    $media = config('queue.connections.redis-media');

    expect($media['driver'])->toBe('redis')
        ->and($media['queue'])->toBe(config('queue.connections.redis.queue').'-media')
        ->and($media['retry_after'])->toBeGreaterThan((new ProcessVideoAssetJob(1))->timeout + 10);

    config(['queue.default' => 'redis']);
    expect((new ProcessVideoAssetJob(1))->connection)->toBe('redis-media');

    // Off Redis (sync in development and tests) they run as everything else does.
    config(['queue.default' => 'sync']);
    expect((new ProcessVideoAssetJob(1))->connection)->toBe('sync');
});

it('rejects a timeout that is missing, disables itself or runs past the window, and accepts one at the edge', function () use ($outsideTheWindow) {
    $classes = [
        'none' => new class {},
        'zero' => new class
        {
            public int $timeout = 0;
        },
        'negative' => new class
        {
            public int $timeout = -5;
        },
        'past the window' => new class
        {
            public int $timeout = 81;
        },
        'inherited, past the window' => InheritedQueuedTask::class,
        'at the edge' => new class
        {
            public int $timeout = 80;
        },
    ];

    expect(array_keys($outsideTheWindow($classes, 80)))->toBe(['none', 'zero', 'negative', 'past the window', 'inherited, past the window']);
});

it('lets a class past the window only while a copy handed out again cannot run or touch the first', function () use ($beyondTheWindow, $exemptionProblems) {
    foreach (array_keys($beyondTheWindow) as $class) {
        expect($exemptionProblems($class))->toBe([], $class);
    }
});

it('refuses the exemption to a class whose second copy could run or touch the first', function () use ($exemptionProblems) {
    expect($exemptionProblems(new class
    {
        public int $tries = 1;
    }))->toBe([])
        ->and($exemptionProblems(new class
        {
            public int $tries = 2;
        }))->toBe(['more than one attempt'])
        ->and($exemptionProblems(new class
        {
            public int $tries = 1;

            public function failed(): void {}
        }))->toBe(['a failed() handler'])
        ->and($exemptionProblems(new class
        {
            public int $tries = 1;

            public function retryUntil(): DateTimeInterface
            {
                return now()->addHour();
            }
        }))->toBe(['a retry deadline'])
        ->and($exemptionProblems(new class
        {
            public int $tries = 1;

            public ?int $retryUntil = null;
        }))->toBe(['a retry deadline']);
});
