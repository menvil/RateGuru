<?php

namespace App\Jobs;

use App\Enums\MediaAuditRunStatus;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use App\Services\Media\Exceptions\MediaAuditAlreadyRunningException;
use App\Services\Media\MediaAuditIssueData;
use App\Services\Media\MediaAuditService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Runs the one expensive, full media audit sweep (MediaAuditService) and
 * persists it as a MediaAuditRun plus its MediaAuditIssue rows — the only
 * place that sweep ever runs. The Filament diagnostics page only ever reads
 * the resulting snapshot; it never triggers this inline from a page
 * request. No constructor properties (every dispatch is identical — "audit
 * everything, right now"), so there's no Eloquent model to serialize and
 * nothing that can go stale between dispatch and execution.
 */
final class RunMediaAuditJob implements ShouldQueue
{
    use Queueable;

    private const int ISSUE_INSERT_CHUNK = 500;

    public int $tries = 1;

    public int $timeout = 3600;

    /** @var list<array<string, mixed>> */
    private array $issueBuffer = [];

    public function handle(MediaAuditService $auditService): void
    {
        $lock = $this->auditLock();

        if (! $lock->get()) {
            throw MediaAuditAlreadyRunningException::make();
        }

        try {
            $this->runAudit($auditService);
        } finally {
            $lock->release();
        }
    }

    private function runAudit(MediaAuditService $auditService): void
    {
        $run = MediaAuditRun::create([
            'started_at' => now(),
            'status' => MediaAuditRunStatus::Running,
        ]);

        try {
            $summary = $auditService->run(
                onIssue: function (MediaAuditIssueData $issue) use ($run): void {
                    $this->bufferIssue($run, $issue);
                },
            );

            $this->flushIssueBuffer();

            $run->update([
                'completed_at' => now(),
                'status' => MediaAuditRunStatus::Completed,
                'assets_checked' => $summary->assetsChecked,
                'variants_checked' => $summary->variantsChecked,
                'healthy_assets' => $summary->healthyAssets,
                'active_unreferenced_assets' => $summary->activeUnreferencedAssets,
                'soft_deleted_within_grace' => $summary->softDeletedWithinGrace,
                'purgeable_assets' => $summary->purgeableAssets,
                'missing_masters' => $summary->missingMasters,
                'missing_variant_files' => $summary->missingVariantFiles,
                'physical_orphan_candidates' => $summary->physicalOrphanCandidates,
                'failed_media_jobs' => $summary->failedMediaJobs,
            ]);

            $this->pruneOldRuns();
        } catch (Throwable $exception) {
            // Whatever issues were already found before the failure are
            // still worth keeping — flushed and left attached to this
            // (failed) run rather than discarded.
            $this->flushIssueBuffer();

            $run->update([
                'completed_at' => now(),
                'status' => MediaAuditRunStatus::Failed,
            ]);

            Log::error('RunMediaAuditJob: full audit failed.', [
                'media_audit_run_id' => $run->id,
                'exception_class' => $exception::class,
            ]);

            // Never swallowed: propagates so Laravel's own queue failure
            // handling records it too, and the caller (the Filament "Run
            // full audit" action, or a retry) sees a real failure — not a
            // silently-incomplete "completed" run.
            throw $exception;
        }
    }

    private function bufferIssue(MediaAuditRun $run, MediaAuditIssueData $issue): void
    {
        $this->issueBuffer[] = [
            'media_audit_run_id' => $run->id,
            'issue_type' => $issue->issueType->value,
            'severity' => $issue->issueType->severity()->value,
            'media_asset_id' => $issue->mediaAssetId,
            'media_variant_id' => $issue->mediaVariantId,
            'disk' => $issue->disk,
            'path' => $issue->path,
            'context' => $issue->context !== null ? json_encode($issue->context) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (count($this->issueBuffer) >= self::ISSUE_INSERT_CHUNK) {
            $this->flushIssueBuffer();
        }
    }

    /**
     * Raw insert(), not create() per row: this can be thousands of rows for
     * a large library with real problems, and going through Eloquent's
     * model-event pipeline one row at a time here would be needless
     * overhead for rows nothing ever listens for events on.
     */
    private function flushIssueBuffer(): void
    {
        if ($this->issueBuffer === []) {
            return;
        }

        MediaAuditIssue::insert($this->issueBuffer);
        $this->issueBuffer = [];
    }

    /**
     * Runs only after a successful completion, per the retention policy —
     * a failed run's own snapshot (all-zero counters, whatever partial
     * issues were flushed above) is left alone rather than pruned away
     * before anyone's seen it.
     */
    private function pruneOldRuns(): void
    {
        $retain = max(1, (int) config('media.diagnostics.audit_run_retention'));

        $idsToKeep = MediaAuditRun::query()
            ->orderByDesc('started_at')
            ->limit($retain)
            ->pluck('id');

        MediaAuditRun::query()->whereNotIn('id', $idsToKeep)->delete();
    }

    /**
     * Same Cache::store('database')/LockProvider pattern as
     * MediaLifecycleService::purgeLock() — see that class's docblock for why
     * `database`, not Redis or config('cache.default'). A single
     * non-blocking attempt: a second full audit dispatched while one is
     * already running fails fast rather than queueing up behind it.
     */
    private function auditLock(): Lock
    {
        $store = Cache::store('database')->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The "database" cache store does not support locks.');
        }

        return $store->lock('media-audit:full', (int) config('media.diagnostics.audit_lock.ttl_seconds'));
    }
}
