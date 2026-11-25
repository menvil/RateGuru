<?php

use App\Jobs\RunMediaAuditJob;
use Illuminate\Contracts\Queue\ShouldQueue;

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

$queuedClasses = function (): array {
    $root = dirname(__DIR__, 3).'/app';
    $classes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || ! str_contains((string) file_get_contents($file->getPathname()), 'ShouldQueue')) {
            continue;
        }

        $class = 'App\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));

        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
};

it('finds the classes the application queues', function () use ($queuedClasses) {
    // A scan that found nothing would pass every rule below.
    expect($queuedClasses())->toContain(RunMediaAuditJob::class)->and(count($queuedClasses()))->toBeGreaterThan(5);
});

it('gives every queued class its own timeout, stopping it with a margin before Redis could hand it to another worker', function () use ($queuedClasses, $beyondTheWindow) {
    // The window as the repository ships it — what a target runs with when its
    // environment leaves the value blank — and as configured here.
    preg_match("/EnvironmentValue::integer\(env\('REDIS_QUEUE_RETRY_AFTER'\), (\d+)\)/", (string) file_get_contents(config_path('queue.php')), $shipped);
    $latestStop = min((int) $shipped[1], (int) config('queue.connections.redis.retry_after')) - 10;

    $outside = [];

    foreach ($queuedClasses() as $class) {
        if (array_key_exists($class, $beyondTheWindow)) {
            continue;
        }

        $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

        if (! is_int($timeout) || $timeout > $latestStop) {
            $outside[$class] = $timeout;
        }
    }

    expect($outside)->toBe([]);
});

it('lets a class past the window only while a copy handed out again cannot run or touch the first', function () use ($beyondTheWindow) {
    foreach (array_keys($beyondTheWindow) as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->getDefaultProperties()['tries'] ?? null)->toBe(1, "{$class} must make a single attempt")
            ->and($reflection->hasMethod('failed'))->toBeFalse("{$class} must have no failed() handler");
    }
});
