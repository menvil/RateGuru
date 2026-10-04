<?php

use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\RunMediaAuditJob;
use App\Services\Media\Exceptions\MediaAuditAlreadyRunningException;
use App\Services\Media\FailedMediaJobReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('surfaces a failed GenerateMediaVariantsJob with its asset id safely extracted', function () {
    $job = new GenerateMediaVariantsJob(42);
    insertFailedJobRow(GenerateMediaVariantsJob::class, serialize($job));

    $records = app(FailedMediaJobReader::class)->recentMediaJobFailures();

    expect($records)->toHaveCount(1);
    expect($records[0]->jobClass)->toBe(GenerateMediaVariantsJob::class);
    expect($records[0]->mediaAssetId)->toBe(42);
});

it('surfaces a failed RunMediaAuditJob with a null asset id, since it has no such property', function () {
    $job = new RunMediaAuditJob;
    insertFailedJobRow(RunMediaAuditJob::class, serialize($job));

    $records = app(FailedMediaJobReader::class)->recentMediaJobFailures();

    expect($records)->toHaveCount(1);
    expect($records[0]->jobClass)->toBe(RunMediaAuditJob::class);
    expect($records[0]->mediaAssetId)->toBeNull();
});

it('ignores a failed job that is not one of the known media job classes', function () {
    insertFailedJobRow('App\\Jobs\\NotifyFollowersAboutNewPostJob', 'O:10:"SomeClass":0:{}');

    $records = app(FailedMediaJobReader::class)->recentMediaJobFailures();

    expect($records)->toBeEmpty();
});

it('never calls unserialize on the stored command — a corrupted serialize() string that would error on unserialize() is still handled safely', function () {
    // A syntactically broken serialize() string: unserialize() on this
    // returns false with an E_WARNING (or throws under strict error
    // handling) rather than a usable object. If FailedMediaJobReader ever
    // called unserialize() here, this test would fail loudly instead of
    // passing quietly — that's the point.
    $corrupted = 'O:34:"App\\Jobs\\GenerateMediaVariantsJob":2:{s:12:"mediaAssetId";i:9;s:5:"force"';
    insertFailedJobRow(GenerateMediaVariantsJob::class, $corrupted);

    $records = app(FailedMediaJobReader::class)->recentMediaJobFailures();

    expect($records)->toHaveCount(1);
    // The regex still matches the (still well-formed, just truncated after
    // this point) mediaAssetId segment even though the string as a whole
    // isn't valid serialize() output.
    expect($records[0]->mediaAssetId)->toBe(9);
});

it('handles a payload that is not valid JSON without throwing', function () {
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => '{not valid json',
        'exception' => 'RuntimeException: boom',
        'failed_at' => now(),
    ]);

    $records = app(FailedMediaJobReader::class)->recentMediaJobFailures();

    expect($records)->toBeEmpty();
});

it('handles a payload missing displayName without throwing', function () {
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => json_encode(['data' => ['command' => 'whatever']]),
        'exception' => 'RuntimeException: boom',
        'failed_at' => now(),
    ]);

    $records = app(FailedMediaJobReader::class)->recentMediaJobFailures();

    expect($records)->toBeEmpty();
});

it('orders results newest failure first', function () {
    insertFailedJobRow(GenerateMediaVariantsJob::class, serialize(new GenerateMediaVariantsJob(1)), failedAt: now()->subHours(2)->toDateTimeString());
    insertFailedJobRow(GenerateMediaVariantsJob::class, serialize(new GenerateMediaVariantsJob(2)), failedAt: now()->toDateTimeString());

    $records = app(FailedMediaJobReader::class)->recentMediaJobFailures();

    expect($records)->toHaveCount(2);
    expect($records[0]->mediaAssetId)->toBe(2);
    expect($records[1]->mediaAssetId)->toBe(1);
});

it('summarizes the exception to its first line, truncated', function () {
    $longMessage = str_repeat('x', 500);
    insertFailedJobRow(GenerateMediaVariantsJob::class, serialize(new GenerateMediaVariantsJob(1)), exception: "RuntimeException: {$longMessage}\n#0 stack trace line");

    $records = app(FailedMediaJobReader::class)->recentMediaJobFailures();

    expect($records)->toHaveCount(1);
    expect($records[0]->exceptionSummary)->not->toContain('#0 stack trace line');
    expect(strlen($records[0]->exceptionSummary))->toBeLessThanOrEqual(303); // Str::limit adds "..."
});

it('counts media job failures independently of the recent-listing cap', function () {
    insertFailedJobRow(GenerateMediaVariantsJob::class, serialize(new GenerateMediaVariantsJob(1)));
    insertFailedJobRow(RunMediaAuditJob::class, serialize(new RunMediaAuditJob));
    insertFailedJobRow('App\\Jobs\\NotifyFollowersAboutNewPostJob', 'O:10:"SomeClass":0:{}');

    expect(app(FailedMediaJobReader::class)->countMediaJobFailures())->toBe(2);
});

it('excludes a MediaAuditAlreadyRunningException failure — an expected, benign lock collision, not a real problem', function () {
    $exceptionText = MediaAuditAlreadyRunningException::make()->__toString();
    insertFailedJobRow(RunMediaAuditJob::class, serialize(new RunMediaAuditJob), exception: $exceptionText);

    expect(app(FailedMediaJobReader::class)->recentMediaJobFailures())->toBeEmpty();
    expect(app(FailedMediaJobReader::class)->countMediaJobFailures())->toBe(0);
});

it('still surfaces a genuine RunMediaAuditJob failure that is not a lock collision', function () {
    insertFailedJobRow(RunMediaAuditJob::class, serialize(new RunMediaAuditJob), exception: "RuntimeException: a real audit failure\n#0 somewhere");

    expect(app(FailedMediaJobReader::class)->recentMediaJobFailures())->toHaveCount(1);
    expect(app(FailedMediaJobReader::class)->countMediaJobFailures())->toBe(1);
});
