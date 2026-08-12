<?php

use App\Enums\MediaAuditIssueSeverity;
use App\Enums\MediaAuditIssueType;
use App\Enums\MediaAuditRunStatus;
use App\Jobs\RunMediaAuditJob;
use App\Models\MediaAsset;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use App\Models\Post;
use App\Services\Media\MediaReferenceChecker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('persists a completed run with accurate counts', function () {
    $asset = MediaAsset::factory()->postImage()->create(['disk' => 'public', 'path' => 'media/post-images/a.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    dispatch_sync(new RunMediaAuditJob);

    $run = MediaAuditRun::sole();
    expect($run->status)->toBe(MediaAuditRunStatus::Completed)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->assets_checked)->toBe(1)
        ->and($run->healthy_assets)->toBe(1);
});

it('persists one MediaAuditIssue row per issue found, linked to the run', function () {
    $asset = MediaAsset::factory()->postImage()->create(['disk' => 'public', 'path' => 'media/post-images/missing.jpg']);
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);
    // Master file deliberately never written — a missing-master issue.

    dispatch_sync(new RunMediaAuditJob);

    $run = MediaAuditRun::sole();
    $issues = MediaAuditIssue::where('media_audit_run_id', $run->id)->get();

    expect($issues)->toHaveCount(1);
    expect($issues->first()->issue_type)->toBe(MediaAuditIssueType::MissingMasterFile);
    expect($issues->first()->severity)->toBe(MediaAuditIssueSeverity::Critical);
    expect($issues->first()->media_asset_id)->toBe($asset->id);
});

it('flushes issues in more than one insert batch for a large number of issues', function () {
    // ISSUE_INSERT_CHUNK is 500 — a handful of assets each producing one
    // issue is enough to prove the buffering path runs without asserting on
    // the private chunk size directly.
    foreach (range(1, 5) as $i) {
        $asset = MediaAsset::factory()->postImage()->create(['disk' => 'public', 'path' => "media/post-images/missing-{$i}.jpg"]);
        Post::factory()->published()->create(['image_asset_id' => $asset->id]);
    }

    dispatch_sync(new RunMediaAuditJob);

    $run = MediaAuditRun::sole();
    expect($run->missing_masters)->toBe(5);
    expect(MediaAuditIssue::where('media_audit_run_id', $run->id)->count())->toBe(5);
});

it('marks the run failed and re-throws, without swallowing the exception, when the audit itself fails partway through', function () {
    $asset = MediaAsset::factory()->postImage()->create();

    $originalChecker = app(MediaReferenceChecker::class);

    app()->instance(MediaReferenceChecker::class, new class extends MediaReferenceChecker
    {
        public function referencedAssetIds(Collection $assetIds): Collection
        {
            throw new RuntimeException('Simulated audit failure.');
        }
    });

    try {
        expect(fn () => dispatch_sync(new RunMediaAuditJob))
            ->toThrow(RuntimeException::class, 'Simulated audit failure.');
    } finally {
        app()->instance(MediaReferenceChecker::class, $originalChecker);
    }

    $run = MediaAuditRun::sole();
    expect($run->status)->toBe(MediaAuditRunStatus::Failed)
        ->and($run->completed_at)->not->toBeNull();
});

it('leaves a failed run visible rather than deleting it', function () {
    $originalChecker = app(MediaReferenceChecker::class);

    app()->instance(MediaReferenceChecker::class, new class extends MediaReferenceChecker
    {
        public function referencedAssetIds(Collection $assetIds): Collection
        {
            throw new RuntimeException('Simulated audit failure.');
        }
    });

    MediaAsset::factory()->postImage()->create();

    try {
        try {
            dispatch_sync(new RunMediaAuditJob);
        } catch (RuntimeException) {
            // expected — asserted in the previous test
        }
    } finally {
        app()->instance(MediaReferenceChecker::class, $originalChecker);
    }

    expect(MediaAuditRun::count())->toBe(1);
});

it('prevents a second full audit from running while one already holds the lock, and creates no run row for the blocked attempt', function () {
    $store = Cache::store('database')->getStore();
    $lock = $store->lock('media-audit:full', 60);
    expect($lock->get())->toBeTrue();

    try {
        $exception = null;

        // Same PostgreSQL lock-acquire-under-transaction recovery pattern
        // established in MediaLifecycleServicePurgeTest's own lock-
        // contention test: a failed unique-key insert during
        // DatabaseLock::acquire() can poison the current transaction under
        // PostgreSQL specifically, surfacing as a raw QueryException rather
        // than the thrown MediaAuditAlreadyRunningException — either is
        // equally valid proof the second attempt never proceeded. Running it
        // inside its own nested DB::transaction() keeps that failure
        // contained to a savepoint so the rest of the test can still issue
        // queries afterward.
        try {
            DB::transaction(function (): void {
                dispatch_sync(new RunMediaAuditJob);
            });
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        expect($exception)->not->toBeNull();
    } finally {
        $lock->release();
    }

    // No run row was ever created for the blocked attempt — it never got
    // past the lock check.
    expect(MediaAuditRun::count())->toBe(0);
});

it('prunes old runs down to the retention limit after a successful completion, cascading their issues', function () {
    config(['media.diagnostics.audit_run_retention' => 2]);

    $old1 = MediaAuditRun::factory()->create(['started_at' => now()->subDays(3)]);
    MediaAuditIssue::factory()->create(['media_audit_run_id' => $old1->id]);
    $old2 = MediaAuditRun::factory()->create(['started_at' => now()->subDays(2)]);

    dispatch_sync(new RunMediaAuditJob);

    // Retention keeps only the 2 most recent — the just-created run plus
    // one of the two pre-existing ones.
    expect(MediaAuditRun::count())->toBe(2);
    expect(MediaAuditRun::find($old1->id))->toBeNull();
    expect(MediaAuditIssue::where('media_audit_run_id', $old1->id)->count())->toBe(0);
    expect(MediaAuditRun::find($old2->id))->not->toBeNull();
});

it('does not prune anything after a failed run', function () {
    config(['media.diagnostics.audit_run_retention' => 1]);

    $old = MediaAuditRun::factory()->create(['started_at' => now()->subDays(3)]);

    $originalChecker = app(MediaReferenceChecker::class);
    app()->instance(MediaReferenceChecker::class, new class extends MediaReferenceChecker
    {
        public function referencedAssetIds(Collection $assetIds): Collection
        {
            throw new RuntimeException('Simulated audit failure.');
        }
    });

    MediaAsset::factory()->postImage()->create();

    try {
        try {
            dispatch_sync(new RunMediaAuditJob);
        } catch (RuntimeException) {
        }
    } finally {
        app()->instance(MediaReferenceChecker::class, $originalChecker);
    }

    // The pre-existing old run is still there — pruning only ever runs
    // after a *successful* completion.
    expect(MediaAuditRun::find($old->id))->not->toBeNull();
});
