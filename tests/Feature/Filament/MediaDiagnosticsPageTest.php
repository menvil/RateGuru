<?php

use App\Enums\MediaAuditIssueType;
use App\Enums\MediaAuditRunStatus;
use App\Enums\MediaHealthStatus;
use App\Filament\Pages\MediaDiagnosticsPage;
use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\RunMediaAuditJob;
use App\Models\MediaAsset;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

it('counts the missing masters of the last completed audit in the sidebar, as critical', function () {
    expect(MediaDiagnosticsPage::getNavigationBadge())->toBeNull();

    MediaAuditRun::factory()->create(['completed_at' => now()->subDay(), 'missing_masters' => 5]);
    MediaAuditRun::factory()->create(['completed_at' => now(), 'missing_masters' => 2]);
    MediaAuditRun::factory()->running()->create(['missing_masters' => 9]);

    expect(MediaDiagnosticsPage::getNavigationBadge())->toBe('2')
        ->and(MediaDiagnosticsPage::getNavigationBadgeColor())->toBe('danger');

    MediaAuditRun::factory()->create(['completed_at' => now()->addMinute(), 'missing_masters' => 0]);

    expect(MediaDiagnosticsPage::getNavigationBadge())->toBeNull();
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

it('memoizes lastAuditRun() so it and lastAuditIssuesCount() only query once between them and always agree on which run is "last"', function () {
    $run = MediaAuditRun::factory()->create([
        'status' => MediaAuditRunStatus::Completed,
        'completed_at' => now(),
    ]);
    MediaAuditIssue::factory()->count(3)->create(['media_audit_run_id' => $run->id]);

    $page = app(MediaDiagnosticsPage::class);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $first = $page->lastAuditRun();
    $second = $page->lastAuditRun();
    $issuesCount = $page->lastAuditIssuesCount();

    // Without memoization, a run completing between these calls could make
    // the "Last audit" panel (lastAuditRun()) and the issue count
    // (lastAuditIssuesCount(), which calls lastAuditRun() again internally)
    // describe two different runs. One query total proves both read the
    // same cached result instead.
    expect($queryCount)->toBe(1)
        ->and($first)->toBe($second)
        ->and($issuesCount)->toBe(3);
});

// --- Run full audit / refresh -----------------------------------------------

it('dispatches a full audit from the top bar and persists a MediaAuditRun', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->dispatch('run-media-audit')
        ->assertOk()
        ->assertDispatched('rg-admin-toast', message: 'Full audit dispatched', tone: 'success');

    expect(MediaAuditRun::count())->toBe(1);
});

it('dispatches no second audit while one is already running, and says so', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    MediaAuditRun::factory()->running()->create(['started_at' => now()]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->assertSee('A full audit is running')
        ->dispatch('run-media-audit')
        ->assertDispatched('rg-admin-toast', message: 'A full audit is already running', tone: 'info');

    Queue::assertNotPushed(RunMediaAuditJob::class);
});

it('treats a stale running run older than the lock ttl as not blocking a new audit', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    MediaAuditRun::factory()->running()->create(['started_at' => now()->subDays(2)]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->dispatch('run-media-audit');

    Queue::assertPushed(RunMediaAuditJob::class);
});

it('reads everything again on Refresh, and answers with the time it did', function () {
    $admin = User::factory()->admin()->create();
    $page = Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class)->assertSee('Unknown');

    MediaAuditRun::factory()->create(['completed_at' => now()]);

    $page->dispatch('refresh-media-diagnostics')
        ->assertDispatched('rg-admin-media-refreshed', at: now()->format('H:i'))
        ->assertSee('Healthy');
});

it('puts Refresh and Run full audit in the top bar', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/media-diagnostics')
        ->assertOk()
        ->assertSeeInOrder(['$dispatch(&#039;refresh-media-diagnostics&#039;)', 'Refresh', '$dispatch(&#039;run-media-audit&#039;)', 'Run full audit'], false);
});

// --- Overview and issues: data scoping, filters, search, pages ----------------

it('shows issues from the latest completed run only, not from an older one', function () {
    $admin = User::factory()->admin()->create();

    $oldRun = MediaAuditRun::factory()->create(['completed_at' => now()->subDay()]);
    $oldIssue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create(['media_audit_run_id' => $oldRun->id]);

    $newRun = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $newIssue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create(['media_audit_run_id' => $newRun->id]);

    $page = Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class);

    expect(listedRow($page, $newIssue))->not->toBeNull()
        ->and(listedRow($page, $oldIssue))->toBeNull();
});

it('narrows the issues to a severity and to an issue type, each counted, from the address', function () {
    $admin = User::factory()->admin()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $critical = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create(['media_audit_run_id' => $run->id]);
    $info = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::PurgeableAsset)->create(['media_audit_run_id' => $run->id]);

    $page = Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class)->set('severity', 'critical');

    expect(listedRow($page, $critical))->not->toBeNull()
        ->and(listedRow($page, $info))->toBeNull()
        ->and(collect($page->viewData('severities'))->firstWhere('value', 'critical')['count'])->toBe('1');

    $page = Livewire::actingAs($admin)
        ->withQueryParams(['type' => MediaAuditIssueType::PurgeableAsset->value])
        ->test(MediaDiagnosticsPage::class);

    expect(listedRow($page, $info))->not->toBeNull()
        ->and(listedRow($page, $critical))->toBeNull();
});

it('reads a severity or a type the page does not offer as every one', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->withQueryParams(['severity' => 'catastrophic', 'type' => ['x'], 'page' => 'abc'])
        ->test(MediaDiagnosticsPage::class)
        ->assertSet('severity', '')
        ->assertSet('type', '')
        ->assertSet('pageNumber', 1);
});

it('narrows the issues to what a figure in the overview counts', function () {
    $admin = User::factory()->admin()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now(), 'missing_masters' => 1]);
    $missing = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create(['media_audit_run_id' => $run->id]);
    $orphan = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::PhysicalOrphanCandidate)->create(['media_audit_run_id' => $run->id]);

    $page = Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class);
    $tile = collect($page->viewData('tiles'))->firstWhere('label', 'Missing masters');

    expect($tile['value'])->toBe('1')
        ->and($tile['badge'])->toBe(['label' => 'Critical', 'tone' => 'danger'])
        ->and(collect($page->viewData('tiles'))->firstWhere('label', 'Total assets')['filter'])->toBeNull();

    $page->call('showIssues', $tile['filter']['severity'], $tile['filter']['type'])
        ->assertSet('type', MediaAuditIssueType::MissingMasterFile->value);

    expect(listedRow($page, $missing))->not->toBeNull()
        ->and(listedRow($page, $orphan))->toBeNull();

    $page->call('clearFilters')->assertSet('type', '');
});

it('searches the issues by asset id', function () {
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

    $page = Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class)->set('q', '#'.$asset->id);

    expect(listedRow($page, $matching))->not->toBeNull()
        ->and(listedRow($page, $other))->toBeNull();

    $page->set('q', 'nothing-like-this')->assertSee('No issues match these filters');
});

it('reads the issues 25 at a time, and starts a new search from the first page', function () {
    $admin = User::factory()->admin()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    MediaAuditIssue::factory()->count(30)->ofType(MediaAuditIssueType::MissingMasterFile)->create(['media_audit_run_id' => $run->id]);

    $page = Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class);

    expect($page->viewData('rows'))->toHaveCount(25);

    $page->call('goToPage', 2);

    expect($page->viewData('rows'))->toHaveCount(5);

    $page->set('q', 'masters')->assertSet('pageNumber', 1);
});

it('says when there is no audit yet, and when the last one found nothing', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class)
        ->assertSee('No audit yet')
        ->assertSee('No audit has ever run.');

    MediaAuditRun::factory()->create(['completed_at' => now()]);

    Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class)
        ->assertSee('No issues')
        ->assertSee('The last audit found nothing to fix.');
});

it('offers on each issue only what can be done about it', function () {
    $admin = User::factory()->admin()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issues = collect([
        MediaAuditIssueType::MissingVariantFile,
        MediaAuditIssueType::ActiveUnreferencedAsset,
        MediaAuditIssueType::PurgeableAsset,
        MediaAuditIssueType::MissingMasterFile,
    ])->mapWithKeys(fn (MediaAuditIssueType $type): array => [$type->value => MediaAuditIssue::factory()->ofType($type)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => MediaAsset::factory()->postImage()->create()->id,
    ])]);
    $orphan = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::PhysicalOrphanCandidate)->create(['media_audit_run_id' => $run->id, 'path' => 'stray/file.jpg']);

    $page = Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class);
    $offered = fn (MediaAuditIssue $issue): array => array_keys(array_filter(array_intersect_key(listedRow($page, $issue), array_flip(['regenerate', 'release', 'purge']))));

    expect($offered($issues['missing_variant_file']))->toBe(['regenerate'])
        ->and($offered($issues['active_unreferenced_asset']))->toBe(['release'])
        ->and($offered($issues['purgeable_asset']))->toBe(['purge'])
        ->and($offered($issues['missing_master_file']))->toBe([])
        ->and(listedRow($page, $orphan)['assetId'])->toBeNull()
        ->and(listedRow($page, $issues['missing_master_file'])['critical'])->toBeTrue();
});

// --- Asset inspector ---------------------------------------------------------

it('opens the asset an issue points to in the inspector, its files checked on disk', function () {
    $admin = User::factory()->admin()->create();
    $asset = createPurgeableAsset();
    Carbon::setTestNow();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::PurgeableAsset)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id,
    ]);

    $page = Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class)
        ->call('inspect', $issue->id)
        ->assertSet('inspecting', $issue->id)
        ->assertSee('Asset #'.$asset->id)
        ->assertSee('Released and past its grace period.')
        ->assertSee('Purge asset')
        // A released asset is not regenerated.
        ->assertDontSee('Force regenerate all variants');

    $inspector = $page->viewData('inspector');

    expect(collect($inspector['files'])->pluck('name')->first())->toBe('master')
        ->and($inspector['files'][0]['exists'])->toBeTrue()
        ->and($inspector['canPurge'])->toBeTrue()
        ->and($inspector['canRelease'])->toBeFalse();

    $page->call('closeInspector')->assertSet('inspecting', null);

    // One overlay at a time: a confirmation asked for from the inspector takes its place.
    $page->call('inspect', $issue->id)
        ->call('askToPurge', $asset->id)
        ->assertSet('inspecting', null)
        ->assertSet('confirming', ['action' => 'purge', 'assetId' => $asset->id]);
});

it('opens nothing for an issue with no asset, like a physical orphan', function () {
    $admin = User::factory()->admin()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::PhysicalOrphanCandidate)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->call('inspect', $issue->id)
        ->assertSet('inspecting', null);
});

it('says no automatic fix exists for a missing master', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();
    $run = MediaAuditRun::factory()->create(['completed_at' => now()]);
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create([
        'media_audit_run_id' => $run->id,
        'media_asset_id' => $asset->id,
    ]);

    Livewire::actingAs($admin)->test(MediaDiagnosticsPage::class)
        ->call('inspect', $issue->id)
        ->assertSee('Master missing')
        ->assertSee('No automatic fix: a new upload or a backup restore is needed.')
        ->assertDontSee('Regenerate missing variants');
});

// --- Repair actions -----------------------------------------------------------

it('regenerates a missing variant without force', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->call('regenerate', $asset->id)
        ->assertDispatched('rg-admin-toast', message: "Regeneration dispatched for asset #{$asset->id}", tone: 'info');

    Queue::assertPushed(GenerateMediaVariantsJob::class, fn ($job): bool => $job->mediaAssetId === $asset->id && $job->force === false);
});

it('force regenerates only once confirmed', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();

    $page = Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->call('askToForceRegenerate', $asset->id)
        ->assertSee('Replace the variants of asset #'.$asset->id.'?');

    Queue::assertNothingPushed();

    $page->call('confirm')->assertSet('confirming', null);

    Queue::assertPushed(GenerateMediaVariantsJob::class, fn ($job): bool => $job->mediaAssetId === $asset->id && $job->force === true);
});

it('releases an asset that is still genuinely unreferenced, once confirmed, via MediaLifecycleService', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->call('askToRelease', $asset->id)
        ->assertSee('Release asset #'.$asset->id.'?')
        ->assertSee('it becomes purgeable')
        ->call('confirm')
        ->assertDispatched('rg-admin-toast', message: "Asset #{$asset->id} released, if it was still unreferenced", tone: 'success');

    expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
});

it('does not release an asset that has since become referenced again, checking again rather than trusting the stale issue snapshot', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();

    // The asset became referenced *after* the audit snapshot was taken.
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->call('askToRelease', $asset->id)
        ->call('confirm');

    expect(MediaAsset::find($asset->id)->trashed())->toBeFalse();
});

it('purges only once the irreversibility is acknowledged, and only an asset still purgeable', function () {
    $admin = User::factory()->admin()->create();
    $asset = createPurgeableAsset();

    $page = Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->call('askToPurge', $asset->id)
        ->assertSee('Purge asset #'.$asset->id.' permanently?')
        ->assertSee('Deletes the master, its 2 variants and the asset’s record.')
        ->call('confirm')
        ->assertHasErrors(['acknowledged' => 'accepted']);

    expect(MediaAsset::withTrashed()->find($asset->id))->not->toBeNull();

    $page->set('acknowledged', true)
        ->call('confirm')
        ->assertDispatched('rg-admin-toast', message: "Asset #{$asset->id} purged", tone: 'success');

    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull();
});

it('does not purge an asset whose grace period has not actually expired, even if the stale issue says purgeable', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();
    $asset->delete(); // trashed moments ago — nowhere near grace-expired

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->call('askToPurge', $asset->id)
        ->set('acknowledged', true)
        ->call('confirm')
        ->assertDispatched('rg-admin-toast', tone: 'info');

    expect(MediaAsset::withTrashed()->find($asset->id))->not->toBeNull();
    expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
});

it('asks to confirm nothing for an asset that does not exist, and forgets a confirmation when it is dismissed', function () {
    $admin = User::factory()->admin()->create();
    $asset = MediaAsset::factory()->postImage()->create();

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->call('askToPurge', $asset->id + 999)
        ->assertSet('confirming', null)
        ->call('askToRelease', $asset->id)
        ->call('closeConfirmation')
        ->assertSet('confirming', null)
        ->assertDontSee('Release asset #'.$asset->id.'?');
});

it('keeps what the confirmation confirms out of the browser\'s hands', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->set('confirming', ['action' => 'purge', 'assetId' => 1]);
})->throws(CannotUpdateLockedPropertyException::class);

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

it('lists the failed media jobs, and retries every asset they were for', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    foreach ([7, 8] as $assetId) {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'sync',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => GenerateMediaVariantsJob::class,
                'data' => ['command' => serialize(new GenerateMediaVariantsJob($assetId))],
            ]),
            'exception' => 'RuntimeException: boom',
            'failed_at' => now(),
        ]);
    }

    Livewire::actingAs($admin)
        ->test(MediaDiagnosticsPage::class)
        ->assertSee('GenerateMediaVariantsJob')
        ->assertSee('Retry all')
        ->call('retryAllFailedMediaJobs')
        ->assertDispatched('rg-admin-toast', message: 'Fresh regeneration dispatched for 2 assets', tone: 'info');

    Queue::assertPushed(GenerateMediaVariantsJob::class, 2);
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
