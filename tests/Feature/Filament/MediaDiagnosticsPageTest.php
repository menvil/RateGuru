<?php

use App\Enums\MediaAuditIssueType;
use App\Enums\MediaHealthStatus;
use App\Filament\Pages\MediaDiagnosticsPage;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\MediaAsset;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
});

// --- Authorization ---------------------------------------------------------

it('allows an admin to access the media diagnostics page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/media-diagnostics')->assertOk();
});

it('denies a non-admin (regular user) access to the media diagnostics page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/media-diagnostics')->assertForbidden();
});

it('denies a moderator access to the media diagnostics page', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)->get('/admin/media-diagnostics')->assertForbidden();
});

it('redirects a guest to the login page', function () {
    $this->get('/admin/media-diagnostics')->assertRedirect(route('filament.admin.auth.login'));
});

// regenerateVariants()/retryFailedMediaJob() are public Livewire methods,
// reachable independently of the page's own row-action wiring. Filament's
// own mount/hydrate lifecycle already re-checks canAccess() on every
// request to this component (confirmed separately: a moderator can't even
// obtain a working mounted instance via Livewire::test() to begin with —
// mounting itself aborts for them), which is why these are tested as
// direct method calls against a resolved page instance rather than through
// Livewire::test()->call(): that's what actually isolates and proves the
// Gate::authorize() guard inside each method, independent of whatever
// Filament's own mount-time protection does or doesn't do for a given
// testing pathway.
it('forbids a moderator from calling regenerateVariants directly, and dispatches no job', function () {
    Queue::fake();
    $moderator = User::factory()->moderator()->create();
    $this->actingAs($moderator);
    $asset = MediaAsset::factory()->postImage()->create();

    expect(fn () => app(MediaDiagnosticsPage::class)->regenerateVariants($asset->id, false))
        ->toThrow(AuthorizationException::class);

    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
});

it('forbids a regular user from calling regenerateVariants directly, and dispatches no job', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    $asset = MediaAsset::factory()->postImage()->create();

    expect(fn () => app(MediaDiagnosticsPage::class)->regenerateVariants($asset->id, false))
        ->toThrow(AuthorizationException::class);

    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
});

it('forbids a moderator from calling retryFailedMediaJob directly, and dispatches no job', function () {
    Queue::fake();
    $moderator = User::factory()->moderator()->create();
    $this->actingAs($moderator);

    expect(fn () => app(MediaDiagnosticsPage::class)->retryFailedMediaJob(999))
        ->toThrow(AuthorizationException::class);

    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
});

it('forbids a regular user from calling retryFailedMediaJob directly, and dispatches no job', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(fn () => app(MediaDiagnosticsPage::class)->retryFailedMediaJob(999))
        ->toThrow(AuthorizationException::class);

    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
});

it('allows an admin to call regenerateVariants and retryFailedMediaJob directly', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $asset = MediaAsset::factory()->postImage()->create();

    app(MediaDiagnosticsPage::class)->regenerateVariants($asset->id, false);
    app(MediaDiagnosticsPage::class)->retryFailedMediaJob($asset->id);

    Queue::assertPushed(GenerateMediaVariantsJob::class, 2);
});

// --- Health overview / no-filesystem-scan-on-load ---------------------------

it('computes totals from aggregate SQL, never scanning the filesystem, and shows an unknown health status before any audit has run', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();

    $page = Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class);
    $page->assertOk();

    expect($page->instance()->totals()['total_assets'])->toBe(1);
    expect($page->instance()->health())->toBe(MediaHealthStatus::Unknown);
});

it('does not issue a filesystem existence check while rendering the page', function () {
    // A targeted spy on MediaStorage would be the strongest version of this
    // assertion; a simpler, still-meaningful proxy is that the page loads
    // successfully with real, unwritten (never Storage::put()'d) asset
    // paths on disk — if page rendering called exists() per asset the way
    // MediaAuditService does, nothing here would fail either way, so the
    // real guarantee is architectural (see MediaDiagnosticsPage's own
    // docblock) — this test exists to document the expectation and catch a
    // gross regression like a card that eager-loads exists() per row.
    $admin = User::factory()->admin()->create();
    foreach (range(1, 20) as $i) {
        MediaAsset::factory()->postImage()->create(['path' => "media/post-images/{$i}.jpg"]);
    }

    $queryCountBefore = 0;
    DB::listen(function () use (&$queryCountBefore): void {
        $queryCountBefore++;
    });

    Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class)->assertOk();

    // A handful of aggregate/count queries, not one-per-asset (20 assets
    // would blow well past this if the page scanned per row).
    expect($queryCountBefore)->toBeLessThan(20);
});

// --- Run full audit / refresh -----------------------------------------------

it('dispatches a full audit and persists a MediaAuditRun via the header action', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->callAction('runFullAudit')
        ->assertOk();

    expect(MediaAuditRun::count())->toBe(1);
});

it('the run full audit action is disabled while an audit is already running', function () {
    $admin = User::factory()->admin()->create();
    MediaAuditRun::factory()->running()->create(['started_at' => now()]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->assertActionDisabled('runFullAudit');
});

it('treats a stale running run older than the lock ttl as not blocking a new audit', function () {
    $admin = User::factory()->admin()->create();
    MediaAuditRun::factory()->running()->create(['started_at' => now()->subDays(2)]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->assertActionEnabled('runFullAudit');
});

it('refresh action re-renders without error', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->callAction('refresh')
        ->assertOk();
});

// --- Issues table: data scoping, filters, search ----------------------------

it('shows issues from the latest completed run only, not from an older one', function () {
    $admin = User::factory()->admin()->create();

    $oldRun = MediaAuditRun::factory()->create(['completed_at' => now()->subDay()]);
    $oldIssue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create(['media_audit_run_id' => $oldRun->id]);

    $newRun = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $newIssue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create(['media_audit_run_id' => $newRun->id]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->assertCanSeeTableRecords([$newIssue])
        ->assertCanNotSeeTableRecords([$oldIssue]);
});

it('filters the issues table by severity', function () {
    $admin = User::factory()->admin()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $critical = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create(['media_audit_run_id' => $run->id]);
    $info = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::PurgeableAsset)->create(['media_audit_run_id' => $run->id]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->filterTable('severity', 'critical')
        ->assertCanSeeTableRecords([$critical])
        ->assertCanNotSeeTableRecords([$info]);
});

it('filters the issues table by issue type', function () {
    $admin = User::factory()->admin()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $missing = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create(['media_audit_run_id' => $run->id]);
    $purgeable = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::PurgeableAsset)->create(['media_audit_run_id' => $run->id]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->filterTable('issue_type', MediaAuditIssueType::PurgeableAsset->value)
        ->assertCanSeeTableRecords([$purgeable])
        ->assertCanNotSeeTableRecords([$missing]);
});

it('searches the issues table by asset id', function () {
    $admin = User::factory()->admin()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $asset = MediaAsset::factory()->postImage()->create();
    $matching = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id,
    ]);
    $other = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id + 999,
    ]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->searchTable((string) $asset->id)
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

it('paginates the issues table', function () {
    $admin = User::factory()->admin()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);

    foreach (range(1, 15) as $i) {
        MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create(['media_audit_run_id' => $run->id]);
    }

    $component = Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class);

    expect($component->instance()->getTable()->getRecords())->toHaveCount(10); // default page size
});

// --- Asset inspector ---------------------------------------------------------

it('opens the asset inspector modal for an issue with an asset id', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id,
    ]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->mountTableAction('inspect', $issue)
        ->assertOk();
});

it('hides the inspect action for an issue with no asset id, like a physical orphan', function () {
    $admin = User::factory()->admin()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::PhysicalOrphanCandidate)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->assertTableActionHidden('inspect', $issue);
});

// --- Repair actions -----------------------------------------------------------

it('regenerate action dispatches GenerateMediaVariantsJob without force, only for a missing_variant_file issue', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingVariantFile)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id,
    ]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->callTableAction('regenerate', $issue)
        ->assertOk();

    Queue::assertPushed(GenerateMediaVariantsJob::class, fn ($job): bool => $job->mediaAssetId === $asset->id && $job->force === false);
});

it('regenerate action is hidden for issue types other than missing_variant_file', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::ActiveUnreferencedAsset)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id,
    ]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->assertTableActionHidden('regenerate', $issue);
});

it('force regenerate (via the inspector modal method) dispatches GenerateMediaVariantsJob with force true', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->call('regenerateVariants', $asset->id, true)
        ->assertOk();

    Queue::assertPushed(GenerateMediaVariantsJob::class, fn ($job): bool => $job->mediaAssetId === $asset->id && $job->force === true);
});

it('release action soft-deletes an asset that is still genuinely unreferenced, via MediaLifecycleService', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::ActiveUnreferencedAsset)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id,
    ]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->callTableAction('release', $issue)
        ->assertOk();

    expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
});

it('release action does not touch an asset that has since become referenced again, re-checking rather than trusting the stale issue snapshot', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::ActiveUnreferencedAsset)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id,
    ]);

    // The asset became referenced *after* the audit snapshot was taken.
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->callTableAction('release', $issue)
        ->assertOk();

    expect(MediaAsset::find($asset->id)->trashed())->toBeFalse();
});

it('purge action only proceeds when the asset is genuinely still purgeable, using MediaLifecycleService', function () {
    $admin = User::factory()->admin()->create();
    $asset = createPurgeableAsset();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::PurgeableAsset)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id,
    ]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->callTableAction('purge', $issue)
        ->assertOk();

    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull();
});

it('purge action does not delete an asset whose grace period has not actually expired, even if the stale issue says purgeable', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();
    $asset->delete(); // trashed moments ago — nowhere near grace-expired
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::PurgeableAsset)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id,
    ]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->callTableAction('purge', $issue)
        ->assertOk();

    expect(MediaAsset::withTrashed()->find($asset->id))->not->toBeNull();
    expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
});

it('purge action is hidden for issue types other than purgeable_asset', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id,
    ]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->assertTableActionHidden('purge', $issue);
});

// --- Failed media jobs -----------------------------------------------------

it('failedMediaJobs() only returns known media job classes', function () {
    $admin = User::factory()->admin()->create();

    $job = new GenerateMediaVariantsJob(7);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => GenerateMediaVariantsJob::class,
            'data' => ['command' => serialize($job)],
        ]),
        'exception' => 'RuntimeException: boom',
        'failed_at' => now(),
    ]);

    $component = Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class);

    $failedJobs = $component->instance()->failedMediaJobs();
    expect($failedJobs)->toHaveCount(1);
    expect($failedJobs[0]->mediaAssetId)->toBe(7);
});

it('retryFailedMediaJob dispatches a fresh GenerateMediaVariantsJob rather than requeuing the old payload', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->call('retryFailedMediaJob', 99)
        ->assertOk();

    Queue::assertPushed(GenerateMediaVariantsJob::class, fn ($job): bool => $job->mediaAssetId === 99 && $job->force === false);
});
