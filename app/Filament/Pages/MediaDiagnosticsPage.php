<?php

namespace App\Filament\Pages;

use App\Enums\MediaAuditIssueSeverity;
use App\Enums\MediaAuditIssueType;
use App\Enums\MediaAuditRunStatus;
use App\Enums\MediaHealthStatus;
use App\Enums\MediaPurgeOutcome;
use App\Filament\Support\AdminNavigationGroup;
use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\RunMediaAuditJob;
use App\Models\MediaAsset;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use App\Models\MediaVariant;
use App\Services\Media\FailedMediaJobReader;
use App\Services\Media\FailedMediaJobRecord;
use App\Services\Media\MediaAssetInspection;
use App\Services\Media\MediaAssetInspector;
use App\Services\Media\MediaHealthResolver;
use App\Services\Media\MediaLifecycleService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Read-only for anything expensive: every value on this page comes from
 * aggregate SQL (byte_size sums, small COUNT queries) or a persisted
 * MediaAuditRun/MediaAuditIssue snapshot from a prior RunMediaAuditJob run —
 * never a filesystem scan. The one exception is the asset inspector modal,
 * which does targeted exists() checks for the single asset being inspected
 * (see MediaAssetInspector) — that's an explicit, narrow, opt-in cost a
 * human triggers one asset at a time, not a page-load cost.
 */
final class MediaDiagnosticsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.media-diagnostics';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::SYSTEM;

    protected static ?string $navigationLabel = 'Media Diagnostics';

    protected static ?string $slug = 'media-diagnostics';

    protected static ?int $navigationSort = 11;

    public static function canAccess(): bool
    {
        return Gate::allows('view-media-diagnostics');
    }

    public function getTitle(): string
    {
        return 'Media Diagnostics';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runFullAudit')
                ->label('Run full audit')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Scans every media asset, variant, and the physical disk. This can take a while on a large library.')
                ->disabled(fn (): bool => $this->auditCurrentlyRunning())
                ->action(function (): void {
                    // Checked before dispatch rather than relying on
                    // catching MediaAuditAlreadyRunningException: dispatch()
                    // only throws it synchronously under QUEUE_CONNECTION=sync
                    // (the only connection this app runs today) — if that
                    // ever changes, dispatch() just enqueues and returns
                    // immediately, and a try/catch here would never see the
                    // job's own lock-contention failure at all. The real
                    // safety net stays the job's own lock, checked again the
                    // moment it actually runs; this is only the UI-facing
                    // "don't bother dispatching a second one" hint, same as
                    // the disabled() check above.
                    if ($this->auditCurrentlyRunning()) {
                        Notification::make()
                            ->title('A full audit is already running')
                            ->warning()
                            ->send();

                        return;
                    }

                    RunMediaAuditJob::dispatch();

                    Notification::make()
                        ->title('Full audit dispatched')
                        ->success()
                        ->send();
                }),
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => null),
        ];
    }

    private ?MediaAuditRun $latestCompletedRun = null;

    private bool $latestCompletedRunResolved = false;

    /**
     * Memoized: health() and table()'s own query closure both call this —
     * Filament re-invokes a table's query closure more than once per
     * request (count, records, per filter), so without caching this would
     * mean more than one identical query per render. The resolved flag
     * (rather than checking `$this->latestCompletedRun !== null`) is what
     * lets a genuine "no completed run yet" result — legitimately null —
     * be cached too, instead of re-querying every call.
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

    private ?MediaAuditRun $lastAuditRun = null;

    private bool $lastAuditRunResolved = false;

    /**
     * The "Last audit" panel shows whatever run happened most recently,
     * regardless of its own status (Running/Completed/Failed) — unlike
     * latestCompletedRun(), which the health cards and issues table use and
     * which deliberately only ever considers a run that actually finished
     * successfully.
     *
     * Memoized the same way latestCompletedRun() is: the Blade view and
     * lastAuditIssuesCount() below both call this independently within the
     * same render. Without caching, a run completing between those two
     * calls could make them disagree about which run is "the last
     * audit" — the panel's own fields (from the first call) and the issue
     * count (from the second) would then describe two different runs.
     * issues_count is eager-loaded here too, so the count below never
     * needs its own extra query.
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

    /**
     * How many MediaAuditIssue rows the "Last audit" panel's run produced —
     * a page-class method rather than the view calling
     * $lastAudit->issues()->count() itself, matching every other data
     * access on this page (totals(), health(), etc.) staying out of Blade.
     * Reads the eager-loaded issues_count from lastAuditRun() above instead
     * of issuing its own count query.
     */
    public function lastAuditIssuesCount(): int
    {
        // Plain -> is correct (not a bug): PHP's ?? uses isset() semantics for
        // property fetches, so a null lastAuditRun() falls through to 0 exactly
        // like ?-> would, without a warning — PHPStan flags ?-> here as redundant.
        return $this->lastAuditRun()->issues_count ?? 0;
    }

    /**
     * A Running row older than the lock's own TTL is treated as abandoned
     * (a crashed worker never got to mark it Failed) rather than a
     * permanent UI lock-out — the job's own Cache lock, which has already
     * expired by then too, is what actually enforces "no two concurrent
     * audits," not this button's disabled state. This is only a UX hint.
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

    /**
     * The asset-inspector modal's one piece of data assembly — kept here
     * rather than in the Blade view, matching every other data access on
     * this page. Null means the asset no longer exists (e.g. already
     * purged); the view renders a "no longer exists" message for that case.
     */
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
        // keeps at most config('media.diagnostics.audit_run_retention')
        // rows in the table — this limit is just how many of those the
        // history panel actually displays at once, not a second retention
        // mechanism.
        return MediaAuditRun::query()->orderByDesc('started_at')->limit(10)->get();
    }

    /** @var list<FailedMediaJobRecord>|null */
    private ?array $failedMediaJobs = null;

    /** @return list<FailedMediaJobRecord> */
    public function failedMediaJobs(): array
    {
        return $this->failedMediaJobs ??= app(FailedMediaJobReader::class)->recentMediaJobFailures();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => MediaAuditIssue::query()
                ->when(
                    $this->latestCompletedRun(),
                    fn (Builder $query, MediaAuditRun $run) => $query->where('media_audit_run_id', $run->id),
                    // No completed run yet: match nothing. whereKey(0) is a
                    // non-raw way to express that — ids auto-increment from
                    // 1, so it can never match a real row.
                    fn (Builder $query) => $query->whereKey(0),
                ))
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(function ($state): string {
                        $value = $state instanceof MediaAuditIssueSeverity ? $state->value : (string) $state;

                        return match ($value) {
                            'critical' => 'danger',
                            'warning' => 'warning',
                            default => 'gray',
                        };
                    }),
                TextColumn::make('issue_type')
                    ->label('Issue')
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof MediaAuditIssueType ? $state->value : (string) $state;

                        return str($value)->replace('_', ' ')->headline()->toString();
                    }),
                TextColumn::make('media_asset_id')
                    ->label('Asset ID')
                    ->placeholder('—')
                    // A custom exact-match callback, not a plain
                    // searchable(): Filament's default search issues a
                    // LIKE against the column, and comparing a text pattern
                    // against an integer-affinity column doesn't reliably
                    // match on SQLite the way it does on PostgreSQL/MariaDB
                    // (a real, driver-specific portability gap, not just a
                    // style preference). An exact match is also the more
                    // useful behavior for an id lookup anyway — nobody
                    // searching "Asset ID" wants fuzzy substring hits
                    // against unrelated ids that happen to share digits.
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        if (ctype_digit($search)) {
                            $query->orWhere('media_asset_id', (int) $search);
                        }

                        return $query;
                    }),
                TextColumn::make('media_variant_id')
                    ->label('Variant')
                    ->placeholder('—'),
                TextColumn::make('disk')
                    ->placeholder('—'),
                TextColumn::make('path')
                    ->placeholder('—')
                    ->limit(50)
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Detected')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->options([
                        'critical' => 'Critical',
                        'warning' => 'Warning',
                        'info' => 'Info',
                    ]),
                SelectFilter::make('issue_type')
                    ->label('Issue type')
                    ->options(array_combine(
                        array_map(fn (MediaAuditIssueType $type) => $type->value, MediaAuditIssueType::cases()),
                        array_map(fn (MediaAuditIssueType $type) => str($type->value)->replace('_', ' ')->headline()->toString(), MediaAuditIssueType::cases()),
                    )),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('inspect')
                    ->label('Inspect')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->visible(fn (MediaAuditIssue $record): bool => $record->media_asset_id !== null)
                    ->modalHeading(fn (MediaAuditIssue $record): string => "Asset #{$record->media_asset_id}")
                    ->modalContent(fn (MediaAuditIssue $record): View => view(
                        'filament.pages.media-diagnostics.asset-inspector',
                        [
                            'assetId' => $record->media_asset_id,
                            'inspection' => $record->media_asset_id !== null
                                ? $this->inspectAsset($record->media_asset_id)
                                : null,
                        ],
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('regenerate')
                    ->label('Regenerate missing variants')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('primary')
                    ->visible(fn (MediaAuditIssue $record): bool => $record->issue_type === MediaAuditIssueType::MissingVariantFile
                        && $record->media_asset_id !== null)
                    ->action(function (MediaAuditIssue $record): void {
                        GenerateMediaVariantsJob::dispatch($record->media_asset_id);

                        Notification::make()
                            ->title("Regeneration dispatched for asset #{$record->media_asset_id}")
                            ->success()
                            ->send();
                    }),
                Action::make('release')
                    ->label('Release')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (MediaAuditIssue $record): bool => $record->issue_type === MediaAuditIssueType::ActiveUnreferencedAsset
                        && $record->media_asset_id !== null)
                    ->action(function (MediaAuditIssue $record): void {
                        // media_asset_id is nullable on the column (an issue
                        // like a physical orphan has none), so this action's
                        // own visible() above already excludes null — this
                        // check exists so releaseAsset() below can declare a
                        // plain `int` parameter, not to guard against a
                        // reachable null here.
                        $assetId = $record->media_asset_id;

                        if ($assetId === null) {
                            return;
                        }

                        $this->releaseAsset($assetId);
                    }),
                Action::make('purge')
                    ->label('Purge')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Permanently deletes this asset and its variant files. Only proceeds if the asset is still genuinely purgeable — the grace period, reference check, and purge lock are all re-verified.')
                    ->visible(fn (MediaAuditIssue $record): bool => $record->issue_type === MediaAuditIssueType::PurgeableAsset
                        && $record->media_asset_id !== null)
                    ->action(function (MediaAuditIssue $record): void {
                        $asset = MediaAsset::withTrashed()->find($record->media_asset_id);

                        if ($asset === null) {
                            Notification::make()->title('Asset already gone')->warning()->send();

                            return;
                        }

                        $result = app(MediaLifecycleService::class)->purge($asset);

                        $notification = Notification::make()
                            ->title("Purge outcome for asset #{$record->media_asset_id}: {$result->outcome->value}");

                        match ($result->outcome) {
                            MediaPurgeOutcome::Purged => $notification->success(),
                            MediaPurgeOutcome::Failed => $notification->danger(),
                            default => $notification->warning(),
                        };

                        $notification->send();
                    }),
            ])
            ->paginated([10, 25, 50]);
    }

    public function regenerateVariants(int $assetId, bool $force = false): void
    {
        // Both this and retryFailedMediaJob() below are public Livewire
        // methods, callable independently of any table row action's own
        // visible()/authorization wiring (the asset inspector modal calls
        // this one directly). Gate::authorize() re-asserts the same check
        // canAccess() already enforces for the page itself.
        Gate::authorize('view-media-diagnostics');

        GenerateMediaVariantsJob::dispatch($assetId, force: $force);

        Notification::make()
            ->title($force
                ? "Force regeneration dispatched for asset #{$assetId}"
                : "Regeneration dispatched for asset #{$assetId}")
            ->success()
            ->send();
    }

    public function retryFailedMediaJob(int $assetId): void
    {
        Gate::authorize('view-media-diagnostics');

        GenerateMediaVariantsJob::dispatch($assetId);

        Notification::make()
            ->title("Fresh regeneration dispatched for asset #{$assetId}")
            ->success()
            ->send();
    }

    /**
     * A plain `int` parameter here (rather than inlining this at the
     * release action's call site) is what lets collect([$assetId]) below
     * type as Collection<int, int> — Collection's generics are invariant,
     * so the DB column's own narrower inferred type (int<0, max>, from
     * media_asset_id's unsignedBigInteger column) doesn't satisfy
     * releaseUnreferenced()'s exact `Collection<int, int>` signature even
     * though every real value trivially is one. Crossing a real function
     * boundary with a declared parameter type is what resets that, without
     * a cast or a suppressed error.
     */
    private function releaseAsset(int $assetId): void
    {
        app(MediaLifecycleService::class)->releaseUnreferenced(collect([$assetId]));

        Notification::make()
            ->title("Asset #{$assetId} released, if it was still unreferenced")
            ->success()
            ->send();
    }
}
