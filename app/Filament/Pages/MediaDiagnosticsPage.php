<?php

namespace App\Filament\Pages;

use App\Enums\MediaAuditIssueSeverity;
use App\Enums\MediaAuditIssueType;
use App\Enums\MediaAuditRunStatus;
use App\Enums\MediaHealthStatus;
use App\Enums\MediaPurgeOutcome;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\ModerationListFormat;
use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\RunMediaAuditJob;
use App\Models\MediaAsset;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use App\Models\MediaVariant;
use App\Queries\Media\MediaAuditIssuesQuery;
use App\Services\Media\FailedMediaJobReader;
use App\Services\Media\FailedMediaJobRecord;
use App\Services\Media\MediaAssetInspection;
use App\Services\Media\MediaAssetInspector;
use App\Services\Media\MediaHealthResolver;
use App\Services\Media\MediaLifecycleService;
use Carbon\CarbonInterface;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Media diagnostics in Admin v2: the media library's health from the last
 * completed audit, the issues it found — narrowed, searched and read 25 at a
 * time — what each can have done to it, the last audit and the ones before
 * it, and the media jobs that failed.
 *
 * Read-only for anything expensive: every value on this page comes from
 * aggregate SQL (byte_size sums, small COUNT queries) or a persisted
 * MediaAuditRun/MediaAuditIssue snapshot from a prior RunMediaAuditJob run —
 * never a filesystem scan. The one exception is the asset inspector, which
 * does targeted exists() checks for the single asset being inspected (see
 * MediaAssetInspector) — that's an explicit, narrow, opt-in cost a human
 * triggers one asset at a time, not a page-load cost.
 */
final class MediaDiagnosticsPage extends Page
{
    protected string $view = 'filament.pages.media-diagnostics';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::SYSTEM;

    protected static ?string $navigationLabel = 'Media diagnostics';

    protected static ?string $slug = 'media-diagnostics';

    protected static ?int $navigationSort = 10;

    /** A severity, or every severity. Typed loosely: whatever a link puts here reads as one. */
    #[Url(except: '')]
    public mixed $severity = '';

    /** An issue type, or every type. */
    #[Url(except: '')]
    public mixed $type = '';

    /** The search over the asset id and the path. */
    #[Url(except: '')]
    public mixed $q = '';

    #[Url(as: 'page', except: 1)]
    public mixed $pageNumber = 1;

    /** The issue whose asset is open in the inspector. */
    #[Locked]
    public ?int $inspecting = null;

    /**
     * The confirmation on screen: what it confirms, for which asset.
     *
     * @var array{action: 'release'|'purge'|'force', assetId: int}|null
     */
    #[Locked]
    public ?array $confirming = null;

    /** The acknowledgement purging asks for. */
    public bool $acknowledged = false;

    private ?MediaAuditRun $latestCompletedRun = null;

    private bool $latestCompletedRunResolved = false;

    private ?MediaAuditRun $lastAuditRun = null;

    private bool $lastAuditRunResolved = false;

    /** @var list<FailedMediaJobRecord>|null */
    private ?array $failedMediaJobs = null;

    public static function canAccess(): bool
    {
        return Gate::allows('view-media-diagnostics');
    }

    public function getTitle(): string
    {
        return 'Media diagnostics';
    }

    /**
     * The sidebar's critical count: the masters the last completed audit
     * found missing, the one critical issue type. One read of that audit's
     * own tally, not a count over its issues.
     */
    public static function getNavigationBadge(): ?string
    {
        $missing = (int) MediaAuditRun::query()
            ->where('status', MediaAuditRunStatus::Completed)
            ->latest('completed_at')
            ->latest('id')
            ->value('missing_masters');

        return $missing > 0 ? (string) $missing : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Critical media issues';
    }

    public function mount(): void
    {
        $this->normalize();
    }

    /** A new filter or search starts again from the first page. */
    public function updated(string $property): void
    {
        if (in_array($property, ['severity', 'type', 'q'], true)) {
            $this->pageNumber = 1;
        }

        $this->normalize();
    }

    public function goToPage(mixed $page): void
    {
        $this->pageNumber = max(1, (int) $page);
    }

    /** A figure in the overview narrows the issues to what it counts. */
    public function showIssues(string $severity = '', string $type = ''): void
    {
        $this->severity = $severity;
        $this->type = $type;
        $this->q = '';
        $this->pageNumber = 1;
        $this->normalize();
    }

    public function clearFilters(): void
    {
        $this->showIssues();
    }

    /** Run full audit in the top bar: dispatches one, unless one is already running. */
    #[On('run-media-audit')]
    public function runFullAudit(): void
    {
        Gate::authorize('view-media-diagnostics');

        // Checked before dispatch rather than relying on catching
        // MediaAuditAlreadyRunningException: dispatch() only throws it
        // synchronously under QUEUE_CONNECTION=sync — if that ever changes,
        // dispatch() just enqueues and returns immediately, and a try/catch
        // here would never see the job's own lock-contention failure at
        // all. The real safety net stays the job's own lock, checked again
        // the moment it actually runs; this is only the screen saying
        // "don't bother dispatching a second one".
        if ($this->auditCurrentlyRunning()) {
            $this->toast('A full audit is already running', tone: 'info');

            return;
        }

        RunMediaAuditJob::dispatch();
        $this->forget();

        $this->toast('Full audit dispatched');
    }

    /** Refresh in the top bar: reads everything again. */
    #[On('refresh-media-diagnostics')]
    public function refreshDiagnostics(): void
    {
        $this->forget();
        $this->dispatch('rg-admin-media-refreshed', at: now()->format('H:i'));
    }

    public function inspect(int $issueId): void
    {
        $issue = MediaAuditIssue::query()->find($issueId);

        if ($issue?->media_asset_id !== null) {
            $this->inspecting = $issueId;
        }
    }

    public function closeInspector(): void
    {
        $this->inspecting = null;
    }

    /** Regenerate on a missing variant: dispatches the variants job for the asset. */
    public function regenerate(int $assetId): void
    {
        $this->regenerateVariants($assetId);
    }

    public function regenerateVariants(int $assetId, bool $force = false): void
    {
        // Both this and retryFailedMediaJob() below are public Livewire
        // methods, callable independently of anything the page offers.
        // Gate::authorize() re-asserts the same check canAccess() already
        // enforces for the page itself.
        Gate::authorize('view-media-diagnostics');

        GenerateMediaVariantsJob::dispatch($assetId, force: $force);

        $this->toast($force
            ? "Force regeneration dispatched for asset #{$assetId}"
            : "Regeneration dispatched for asset #{$assetId}", tone: 'info');
    }

    public function retryFailedMediaJob(int $assetId): void
    {
        Gate::authorize('view-media-diagnostics');

        GenerateMediaVariantsJob::dispatch($assetId);

        $this->toast("Fresh regeneration dispatched for asset #{$assetId}", tone: 'info');
    }

    /** Retry all: a fresh regeneration for every asset a failed media job was for. */
    public function retryAllFailedMediaJobs(): void
    {
        Gate::authorize('view-media-diagnostics');

        $assetIds = collect($this->failedMediaJobs())->pluck('mediaAssetId')->filter()->unique()->values();

        foreach ($assetIds as $assetId) {
            GenerateMediaVariantsJob::dispatch((int) $assetId);
        }

        $this->toast('Fresh regeneration dispatched for '.$assetIds->count().' '.Str::plural('asset', $assetIds->count()), tone: 'info');
    }

    public function askToRelease(int $assetId): void
    {
        $this->ask('release', $assetId);
    }

    public function askToPurge(int $assetId): void
    {
        $this->ask('purge', $assetId);
    }

    public function askToForceRegenerate(int $assetId): void
    {
        $this->ask('force', $assetId);
    }

    public function closeConfirmation(): void
    {
        $this->confirming = null;
        $this->acknowledged = false;
        $this->resetErrorBag();
    }

    /** Does what the confirmation on screen confirms. */
    public function confirm(): void
    {
        Gate::authorize('view-media-diagnostics');

        if ($this->confirming === null) {
            return;
        }

        ['action' => $action, 'assetId' => $assetId] = $this->confirming;

        if ($action === 'purge') {
            $this->validate(['acknowledged' => ['accepted']], ['acknowledged.accepted' => 'Tick the box to confirm the files are deleted for good.']);
        }

        $this->closeConfirmation();
        $this->inspecting = null;

        match ($action) {
            'release' => $this->releaseAsset($assetId),
            'purge' => $this->purgeAsset($assetId),
            default => $this->regenerateVariants($assetId, force: true),
        };
    }

    /**
     * Memoized: the overview, the issues and their counts all read it within
     * one render. The resolved flag (rather than checking
     * `$this->latestCompletedRun !== null`) is what lets a genuine "no
     * completed run yet" result — legitimately null — be cached too.
     */
    public function latestCompletedRun(): ?MediaAuditRun
    {
        if (! $this->latestCompletedRunResolved) {
            $this->latestCompletedRun = MediaAuditRun::query()
                ->where('status', MediaAuditRunStatus::Completed)
                // id as a tiebreaker: completed_at has only second-level
                // precision, so two runs completing within the same second
                // would otherwise sort arbitrarily.
                ->latest('completed_at')
                ->latest('id')
                ->first();
            $this->latestCompletedRunResolved = true;
        }

        return $this->latestCompletedRun;
    }

    /**
     * The "Last audit" card shows whatever run happened most recently,
     * regardless of its own status (Running/Completed/Failed) — unlike
     * latestCompletedRun(), which the overview and the issues use and which
     * deliberately only ever considers a run that actually finished.
     *
     * Memoized the same way, so the card's own fields and the issue count
     * below always describe the same run, even when one completes between
     * the two reads. issues_count is eager-loaded here, so the count never
     * needs its own query.
     */
    public function lastAuditRun(): ?MediaAuditRun
    {
        if (! $this->lastAuditRunResolved) {
            $this->lastAuditRun = MediaAuditRun::query()
                ->withCount('issues')
                ->latest('started_at')
                ->first();
            $this->lastAuditRunResolved = true;
        }

        return $this->lastAuditRun;
    }

    /** How many issues the last audit recorded, from the count lastAuditRun() loaded. */
    public function lastAuditIssuesCount(): int
    {
        // Plain -> is correct (not a bug): PHP's ?? uses isset() semantics for
        // property fetches, so a null lastAuditRun() falls through to 0 exactly
        // like ?-> would, without a warning — PHPStan flags ?-> here as redundant.
        return $this->lastAuditRun()->issues_count ?? 0;
    }

    /**
     * A Running row older than the lock's own TTL is treated as abandoned
     * (a crashed worker never got to mark it Failed) rather than a permanent
     * lock-out — the job's own Cache lock, which has already expired by then
     * too, is what actually enforces "no two concurrent audits".
     */
    public function auditCurrentlyRunning(): bool
    {
        return MediaAuditRun::query()
            ->where('status', MediaAuditRunStatus::Running)
            ->where('started_at', '>=', now()->subSeconds((int) config('media.diagnostics.audit_lock.ttl_seconds')))
            ->exists();
    }

    public function health(): MediaHealthStatus
    {
        return app(MediaHealthResolver::class)->resolve($this->latestCompletedRun());
    }

    /**
     * @return array{total_assets: int, total_variants: int, tracked_storage_bytes: int}
     */
    public function totals(): array
    {
        return [
            'total_assets' => MediaAsset::withTrashed()->count(),
            'total_variants' => MediaVariant::query()->count(),
            'tracked_storage_bytes' => (int) MediaAsset::withTrashed()->sum('byte_size')
                + (int) MediaVariant::query()->sum('byte_size'),
        ];
    }

    /** What the inspector shows for one asset; null when the asset no longer exists (purged, say). */
    public function inspectAsset(int $assetId): ?MediaAssetInspection
    {
        $asset = MediaAsset::withTrashed()->find($assetId);

        if ($asset === null) {
            return null;
        }

        return app(MediaAssetInspector::class)->inspect($asset);
    }

    /** @return EloquentCollection<int, MediaAuditRun> */
    public function recentRuns(): EloquentCollection
    {
        // The retention policy (RunMediaAuditJob::pruneOldRuns()) already
        // keeps at most config('media.diagnostics.audit_run_retention') rows —
        // this limit is just how many of those the history card shows.
        return MediaAuditRun::query()->orderByDesc('started_at')->limit(5)->get();
    }

    /** @return list<FailedMediaJobRecord> */
    public function failedMediaJobs(): array
    {
        return $this->failedMediaJobs ??= app(FailedMediaJobReader::class)->recentMediaJobFailures();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $run = $this->latestCompletedRun();
        $last = $this->lastAuditRun();
        $issues = app(MediaAuditIssuesQuery::class);
        $counts = $issues->counts($run);
        $paginator = $issues->paginate($run, MediaAuditIssueSeverity::tryFrom((string) $this->severity), MediaAuditIssueType::tryFrom((string) $this->type), (string) $this->q, (int) $this->pageNumber);
        $totals = $this->totals();
        $jobs = $this->failedMediaJobs();

        return [
            'health' => self::healthBadge($this->health()),
            'running' => $this->auditCurrentlyRunning(),
            'auditLine' => match (true) {
                $run !== null && $run->completed_at !== null => 'Last audit completed '.ModerationListFormat::when($run->completed_at).' · '.number_format(array_sum($counts['severity'])).' '.Str::plural('issue', array_sum($counts['severity'])),
                default => 'No audit has completed yet',
            },
            'disks' => collect((array) config('media.disks'))->filter(fn (mixed $disk): bool => is_string($disk))->unique()->implode(', '),
            'tiles' => $this->tiles($run, $totals, $counts, $jobs),
            'issuesFrom' => $run?->completed_at !== null ? ModerationListFormat::when($run->completed_at) : null,
            'severities' => [
                ['value' => '', 'label' => 'All severities', 'trigger' => 'All'],
                ...array_map(fn (MediaAuditIssueSeverity $case): array => ['value' => $case->value, 'label' => Str::ucfirst($case->value), 'count' => number_format($counts['severity'][$case->value])], MediaAuditIssueSeverity::cases()),
            ],
            'types' => [
                ['value' => '', 'label' => 'All types', 'trigger' => 'All'],
                ...array_map(fn (MediaAuditIssueType $case): array => ['value' => $case->value, 'label' => self::typeLabel($case), 'count' => number_format($counts['type'][$case->value])], MediaAuditIssueType::cases()),
            ],
            'paginator' => $paginator,
            'rows' => array_map(fn (MediaAuditIssue $issue): array => $this->row($issue), $paginator->items()),
            'filtered' => $this->severity !== '' || $this->type !== '' || trim((string) $this->q) !== '',
            'lastAudit' => $last === null ? null : [
                'started' => $last->started_at->format('d M Y H:i:s'),
                'completed' => $last->completed_at?->format('d M Y H:i:s'),
                'duration' => $last->completed_at !== null ? self::duration($last->started_at, $last->completed_at) : null,
                'status' => self::runStatus($last->status),
                'assets' => number_format((int) $last->assets_checked),
                'variants' => number_format((int) $last->variants_checked),
                'issues' => number_format($this->lastAuditIssuesCount()),
            ],
            'history' => $this->recentRuns()->map(fn (MediaAuditRun $past): array => [
                'started' => ModerationListFormat::when($past->started_at),
                'status' => self::runStatus($past->status),
                'meta' => $past->status === MediaAuditRunStatus::Failed
                    ? 'Stopped after '.number_format((int) $past->assets_checked).' assets'
                    : number_format((int) $past->assets_checked).' assets · '.number_format((int) $past->missing_masters).' missing masters · '.number_format((int) $past->purgeable_assets).' purgeable'.($past->completed_at !== null ? ' · '.self::duration($past->started_at, $past->completed_at) : ''),
            ])->all(),
            'jobs' => array_map(fn (FailedMediaJobRecord $job): array => [
                'job' => class_basename($job->jobClass),
                'assetId' => $job->mediaAssetId,
                'failedAt' => ModerationListFormat::when($job->failedAt),
                'exception' => $job->exceptionSummary,
            ], $jobs),
            'inspector' => $this->inspector(),
            'confirmation' => $this->confirmation(),
        ];
    }

    /**
     * The overview's figures, each with a note; the ones that count issues
     * narrow the list to them.
     *
     * @param  array{total_assets: int, total_variants: int, tracked_storage_bytes: int}  $totals
     * @param  array{severity: array<string, int>, type: array<string, int>}  $counts
     * @param  list<FailedMediaJobRecord>  $jobs
     * @return list<array{label: string, value: string, note: string, badge: ?array{label: string, tone: string}, filter: ?array{severity: string, type: string}}>
     */
    private function tiles(?MediaAuditRun $run, array $totals, array $counts, array $jobs): array
    {
        $critical = $counts['severity'][MediaAuditIssueSeverity::Critical->value] ?? 0;
        $warnings = $counts['severity'][MediaAuditIssueSeverity::Warning->value] ?? 0;
        $type = fn (MediaAuditIssueType $case): array => ['severity' => '', 'type' => $case->value];

        return [
            ['label' => 'Health status', 'value' => self::healthBadge($this->health())['label'], 'note' => number_format($critical).' critical · '.number_format($warnings).' '.Str::plural('warning', $warnings), 'badge' => null, 'filter' => $critical > 0 ? ['severity' => MediaAuditIssueSeverity::Critical->value, 'type' => ''] : null],
            ['label' => 'Total assets', 'value' => number_format($totals['total_assets']), 'note' => 'Tracked in the database', 'badge' => null, 'filter' => null],
            ['label' => 'Total variants', 'value' => number_format($totals['total_variants']), 'note' => $totals['total_assets'] > 0 ? number_format($totals['total_variants'] / $totals['total_assets'], 1).' per asset on average' : 'None yet', 'badge' => null, 'filter' => null],
            ['label' => 'Tracked storage', 'value' => Number::fileSize($totals['tracked_storage_bytes'], precision: 1), 'note' => 'Masters and variants', 'badge' => null, 'filter' => null],
            ['label' => 'Missing masters', 'value' => number_format((int) $run?->missing_masters), 'note' => 'Originals not on disk', 'badge' => (int) $run?->missing_masters > 0 ? ['label' => 'Critical', 'tone' => 'danger'] : null, 'filter' => $type(MediaAuditIssueType::MissingMasterFile)],
            ['label' => 'Missing variants', 'value' => number_format((int) $run?->missing_variant_files), 'note' => 'Can be regenerated', 'badge' => (int) $run?->missing_variant_files > 0 ? ['label' => 'Warning', 'tone' => 'warning'] : null, 'filter' => $type(MediaAuditIssueType::MissingVariantFile)],
            ['label' => 'Active unreferenced', 'value' => number_format((int) $run?->active_unreferenced_assets), 'note' => 'Candidates for release', 'badge' => null, 'filter' => $type(MediaAuditIssueType::ActiveUnreferencedAsset)],
            ['label' => 'Purgeable assets', 'value' => number_format((int) $run?->purgeable_assets), 'note' => 'Past their grace period', 'badge' => null, 'filter' => $type(MediaAuditIssueType::PurgeableAsset)],
            ['label' => 'Physical orphan candidates', 'value' => number_format((int) $run?->physical_orphan_candidates), 'note' => 'Files with no record', 'badge' => null, 'filter' => $type(MediaAuditIssueType::PhysicalOrphanCandidate)],
            ['label' => 'Failed media jobs', 'value' => number_format(count($jobs)), 'note' => $jobs !== [] ? 'Last failure '.ModerationListFormat::when($jobs[0]->failedAt) : 'Queue healthy', 'badge' => $jobs !== [] ? ['label' => 'Failed', 'tone' => 'danger'] : null, 'filter' => null],
        ];
    }

    /**
     * One issue as its row shows it, with what can be done about it.
     *
     * @return array<string, mixed>
     */
    private function row(MediaAuditIssue $issue): array
    {
        $asset = $issue->media_asset_id;

        return [
            'id' => (int) $issue->id,
            'severity' => self::severityBadge($issue->severity),
            'critical' => $issue->severity === MediaAuditIssueSeverity::Critical,
            'type' => self::typeLabel($issue->issue_type),
            'asset' => $asset !== null ? '#'.$asset : '—',
            'variant' => $issue->variant !== null ? $issue->variant->name->value : ($issue->media_variant_id !== null ? '#'.$issue->media_variant_id : '—'),
            'disk' => $issue->disk ?? '—',
            'path' => $issue->path,
            'detected' => $issue->created_at !== null ? ModerationListFormat::when($issue->created_at) : '—',
            'assetId' => $asset,
            'regenerate' => $asset !== null && $issue->issue_type === MediaAuditIssueType::MissingVariantFile,
            'release' => $asset !== null && $issue->issue_type === MediaAuditIssueType::ActiveUnreferencedAsset,
            'purge' => $asset !== null && $issue->issue_type === MediaAuditIssueType::PurgeableAsset,
        ];
    }

    /**
     * The asset open in the inspector, and what the issue that led there
     * means for it.
     *
     * @return array<string, mixed>|null
     */
    private function inspector(): ?array
    {
        $issue = $this->inspecting !== null ? MediaAuditIssue::query()->find($this->inspecting) : null;

        if ($issue === null || $issue->media_asset_id === null) {
            return null;
        }

        $inspection = $this->inspectAsset($issue->media_asset_id);
        $asset = $inspection?->asset;
        $missing = $inspection?->variants->filter(fn (array $entry): bool => ! $entry['exists'])->count() ?? 0;

        return [
            'issueId' => (int) $issue->id,
            'assetId' => (int) $issue->media_asset_id,
            'type' => $issue->issue_type,
            'typeLabel' => self::typeLabel($issue->issue_type),
            'detected' => $issue->created_at !== null ? ModerationListFormat::when($issue->created_at) : null,
            'severity' => self::severityBadge($issue->severity),
            'explain' => match ($issue->issue_type) {
                MediaAuditIssueType::MissingMasterFile => 'The original upload is gone from its disk. Existing variants still render, but nothing can be regenerated from it: a new upload or a backup restore is needed.',
                MediaAuditIssueType::MissingVariantFile => 'Some of its variants are missing. The master is intact, so they can be regenerated.',
                MediaAuditIssueType::ActiveUnreferencedAsset => 'Still marked active, but nothing references it. Release it to start the media lifecycle.',
                MediaAuditIssueType::PurgeableAsset => 'Released and past its grace period. Purging deletes the files for good.',
                default => 'Found by the last audit.',
            },
            'exists' => $inspection !== null,
            'path' => $asset?->path,
            'details' => $asset === null ? [] : [
                ['icon' => 'image', 'label' => 'Kind', 'value' => Str::headline($asset->kind->value), 'mono' => false],
                ['icon' => 'link', 'label' => 'Used by', 'value' => self::references($inspection), 'mono' => false],
                ['icon' => 'hard-drive', 'label' => 'Disk', 'value' => $asset->disk, 'mono' => true],
                ['icon' => 'folder', 'label' => 'Path', 'value' => $asset->path, 'mono' => true],
                ['icon' => 'files', 'label' => 'Size', 'value' => Number::fileSize((int) $asset->byte_size, precision: 1), 'mono' => false],
                ['icon' => 'image', 'label' => 'Dimensions', 'value' => $asset->width !== null && $asset->height !== null ? $asset->width.' × '.$asset->height : '—', 'mono' => false],
                ['icon' => 'files', 'label' => 'Type', 'value' => $asset->mime_type, 'mono' => true],
                ['icon' => 'calendar', 'label' => 'Uploaded', 'value' => $asset->created_at !== null ? $asset->created_at->format('d M Y H:i') : '—', 'mono' => false],
                ['icon' => 'activity', 'label' => 'Lifecycle', 'value' => self::lifecycle($inspection), 'mono' => false],
            ],
            'files' => $inspection === null ? [] : [
                ['name' => 'master', 'meta' => $inspection->masterExists ? Number::fileSize((int) $asset->byte_size, precision: 1) : 'Missing', 'exists' => $inspection->masterExists],
                ...$inspection->variants->map(fn (array $entry): array => [
                    'name' => $entry['variant']->name->value,
                    'meta' => $entry['exists'] ? Number::fileSize((int) $entry['variant']->byte_size, precision: 1) : 'Missing',
                    'exists' => $entry['exists'],
                ])->values()->all(),
            ],
            'filesSummary' => $inspection === null ? null : (! $inspection->masterExists ? 'Master missing' : ($missing > 0 ? $missing.' missing' : 'All present')),
            // A released asset is on its way out: nothing regenerates it.
            'canRegenerate' => $inspection !== null && $inspection->masterExists && ! $inspection->asset->trashed(),
            'canRelease' => $issue->issue_type === MediaAuditIssueType::ActiveUnreferencedAsset && $inspection !== null,
            'canPurge' => $issue->issue_type === MediaAuditIssueType::PurgeableAsset && $inspection !== null,
        ];
    }

    /**
     * The confirmation on screen, with the asset it is about.
     *
     * @return array{action: string, assetId: int, size: string, path: ?string, variants: int, graceDays: int}|null
     */
    private function confirmation(): ?array
    {
        if ($this->confirming === null) {
            return null;
        }

        $asset = MediaAsset::withTrashed()->withCount('variants')->find($this->confirming['assetId']);

        return [
            'action' => $this->confirming['action'],
            'assetId' => $this->confirming['assetId'],
            'size' => $asset !== null ? Number::fileSize((int) $asset->byte_size, precision: 1) : '—',
            'path' => $asset?->path,
            'variants' => (int) ($asset->variants_count ?? 0),
            'graceDays' => (int) config('media.lifecycle.purge_grace_days'),
        ];
    }

    private function releaseAsset(int $assetId): void
    {
        // releaseUnreferenced() checks the references again: an asset used
        // since the audit is left as it is.
        app(MediaLifecycleService::class)->releaseUnreferenced(collect([$assetId]));
        $this->forget();

        $this->toast("Asset #{$assetId} released, if it was still unreferenced");
    }

    private function purgeAsset(int $assetId): void
    {
        $asset = MediaAsset::withTrashed()->find($assetId);

        if ($asset === null) {
            $this->toast("Asset #{$assetId} is already gone", tone: 'info');

            return;
        }

        // purge() checks the grace period, the references and the purge
        // lock again, and refuses an asset that is no longer purgeable.
        $result = app(MediaLifecycleService::class)->purge($asset);
        $this->forget();

        match ($result->outcome) {
            MediaPurgeOutcome::Purged => $this->toast("Asset #{$assetId} purged"),
            MediaPurgeOutcome::Failed => $this->toast("Purging asset #{$assetId} failed. Nothing was deleted that could be kept.", tone: 'error'),
            default => $this->toast("Asset #{$assetId} was not purged: ".Str::lower(Str::headline($result->outcome->value)), tone: 'info'),
        };
    }

    private function ask(string $action, int $assetId): void
    {
        Gate::authorize('view-media-diagnostics');

        if (MediaAsset::withTrashed()->whereKey($assetId)->exists()) {
            // One overlay at a time: asked from the inspector, the
            // confirmation takes its place.
            $this->inspecting = null;
            $this->confirming = ['action' => $action, 'assetId' => $assetId];
            $this->acknowledged = false;
            $this->resetErrorBag();
        }
    }

    /** Lets the memoized reads read again, after an action changed what they read. */
    private function forget(): void
    {
        $this->latestCompletedRunResolved = false;
        $this->lastAuditRunResolved = false;
        $this->failedMediaJobs = null;
    }

    /** @param array{label: string, event: string, params: array<string, mixed>}|null $action */
    private function toast(string $message, ?array $action = null, string $tone = 'success'): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone, action: $action);
    }

    /** @return array{label: string, tone: string, dot: bool} */
    private static function healthBadge(MediaHealthStatus $health): array
    {
        return match ($health) {
            MediaHealthStatus::Healthy => ['label' => 'Healthy', 'tone' => 'success', 'dot' => true],
            MediaHealthStatus::Warning => ['label' => 'Degraded', 'tone' => 'warning', 'dot' => false],
            MediaHealthStatus::Critical => ['label' => 'Critical', 'tone' => 'danger', 'dot' => false],
            MediaHealthStatus::Unknown => ['label' => 'Unknown', 'tone' => 'neutral', 'dot' => false],
        };
    }

    /** @return array{label: string, tone: string} */
    private static function severityBadge(MediaAuditIssueSeverity $severity): array
    {
        return match ($severity) {
            MediaAuditIssueSeverity::Critical => ['label' => 'Critical', 'tone' => 'danger'],
            MediaAuditIssueSeverity::Warning => ['label' => 'Warning', 'tone' => 'warning'],
            default => ['label' => Str::ucfirst($severity->value), 'tone' => 'info'],
        };
    }

    /** @return array{label: string, tone: string, dot: bool} */
    private static function runStatus(MediaAuditRunStatus $status): array
    {
        return match ($status) {
            MediaAuditRunStatus::Completed => ['label' => 'Completed', 'tone' => 'success', 'dot' => false],
            MediaAuditRunStatus::Failed => ['label' => 'Failed', 'tone' => 'danger', 'dot' => false],
            MediaAuditRunStatus::Running => ['label' => 'Running', 'tone' => 'info', 'dot' => true],
        };
    }

    /** How long an audit ran, as “6m 29s”. */
    private static function duration(CarbonInterface $started, CarbonInterface $completed): string
    {
        return $started->diffForHumans($completed, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2, 'short' => true]);
    }

    private static function typeLabel(MediaAuditIssueType $type): string
    {
        return Str::ucfirst(Str::lower(Str::headline($type->value)));
    }

    private static function references(?MediaAssetInspection $inspection): string
    {
        if ($inspection === null || ($inspection->referencingPosts->isEmpty() && $inspection->referencingUsers->isEmpty())) {
            return 'Nothing references it';
        }

        return $inspection->referencingPosts->map(fn ($post): string => 'Post #'.$post->id.($post->trashed() ? ' (deleted)' : ''))
            ->merge($inspection->referencingUsers->map(fn ($user): string => '@'.$user->username.' (avatar)'))
            ->implode(', ');
    }

    private static function lifecycle(MediaAssetInspection $inspection): string
    {
        $asset = $inspection->asset;

        if (! $asset->trashed()) {
            return $inspection->referenced ? 'Active · referenced' : 'Active · not referenced';
        }

        return 'Released'.($asset->deleted_at !== null ? ' '.$asset->deleted_at->format('d M Y') : '')
            .($inspection->purgeable ? ' · purgeable now' : ($inspection->purgeableAt !== null ? ' · purgeable from '.$inspection->purgeableAt->format('d M Y') : ''));
    }

    /** Whatever a link or a forged update put here, read as something the page offers. */
    private function normalize(): void
    {
        $this->severity = is_string($this->severity) && MediaAuditIssueSeverity::tryFrom($this->severity) !== null ? $this->severity : '';
        $this->type = is_string($this->type) && MediaAuditIssueType::tryFrom($this->type) !== null ? $this->type : '';
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 200) : '';
        $this->pageNumber = max(1, is_numeric($this->pageNumber) ? (int) $this->pageNumber : 1);
    }
}
