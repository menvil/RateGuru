<?php

namespace App\Services\Media;

use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\RunMediaAuditJob;
use App\Models\FailedJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads `failed_jobs`, restricted to the actual media job classes this app
 * dispatches — never a generic "every failed job" view. Deliberately never
 * calls unserialize() on payload.data.command (that's the literal serialized
 * job object — running it through unserialize() would reconstruct arbitrary
 * PHP objects from stored data, a real object-injection surface even for
 * data this app wrote itself, since a corrupted/legacy-format row could
 * behave unpredictably). Everything read here comes from either
 * json_decode() (always safe) or a plain-text regex over the still-encoded
 * serialize() string — GenerateMediaVariantsJob's $mediaAssetId is a public
 * readonly int, so PHP's serialize() format holds it as literal,
 * regex-matchable text (`s:12:"mediaAssetId";i:42;`), never requiring the
 * object to actually be reconstructed.
 */
final class FailedMediaJobReader
{
    /**
     * How far back (by most-recent-first) we're willing to scan across ALL
     * failed jobs, not just media ones, before giving up — a defensive
     * bound, not the media-specific display cap below. Deliberately not a
     * SQL-level pre-filter on the class name: `payload` is a JSON blob, and
     * a LIKE pattern built from a backslash-containing class name (every
     * PHP namespace) runs straight into PostgreSQL's LIKE operator having
     * an *implicit* default ESCAPE character of its own (backslash) — a
     * bound parameter's literal double-backslash bytes (from JSON-escaping
     * the class name) silently fail to match the identically-escaped bytes
     * actually stored, because Postgres consumes them as an escape sequence
     * first. Filtering in PHP via json_decode() after a bounded fetch sidesteps
     * that (and any equivalent MariaDB/SQLite quirk) entirely, at the cost of
     * also fetching non-media rows within the scan window.
     */
    private const int SCAN_LIMIT = 500;

    private const int RECENT_LIMIT = 50;

    /** @var list<class-string> */
    private const array KNOWN_MEDIA_JOB_CLASSES = [
        GenerateMediaVariantsJob::class,
        RunMediaAuditJob::class,
    ];

    /**
     * @return list<FailedMediaJobRecord> the most recent failures across the
     *                                    known media job classes, newest
     *                                    first, capped at RECENT_LIMIT — a
     *                                    diagnostics panel, not a queue
     *                                    browser, so an unbounded listing
     *                                    was never the goal.
     */
    public function recentMediaJobFailures(): array
    {
        return $this->mediaJobFailures()->take(self::RECENT_LIMIT)->values()->all();
    }

    /** @return int total count of failed jobs across the known media job classes, for MediaAuditSummary */
    public function countMediaJobFailures(): int
    {
        return $this->mediaJobFailures()->count();
    }

    /** @return Collection<int, FailedMediaJobRecord> newest failure first */
    private function mediaJobFailures(): Collection
    {
        return FailedJob::query()
            ->orderByDesc('failed_at')
            ->limit(self::SCAN_LIMIT)
            ->get()
            ->map(fn (FailedJob $row): ?FailedMediaJobRecord => $this->parseRow($row))
            ->filter()
            ->values();
    }

    private function parseRow(FailedJob $row): ?FailedMediaJobRecord
    {
        /** @var mixed $payload */
        $payload = json_decode((string) $row->payload, true);

        if (! is_array($payload)) {
            return null;
        }

        $displayName = $payload['displayName'] ?? null;

        if (! is_string($displayName) || ! in_array($displayName, self::KNOWN_MEDIA_JOB_CLASSES, true)) {
            return null;
        }

        $commandString = $payload['data']['command'] ?? null;

        return new FailedMediaJobRecord(
            uuid: (string) $row->uuid,
            jobClass: $displayName,
            mediaAssetId: is_string($commandString) ? $this->extractMediaAssetId($commandString) : null,
            failedAt: Carbon::parse((string) $row->failed_at),
            exceptionSummary: $this->summarizeException((string) $row->exception),
        );
    }

    private function extractMediaAssetId(string $serializedCommand): ?int
    {
        if (preg_match('/s:12:"mediaAssetId";i:(\d+);/', $serializedCommand, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function summarizeException(string $exception): string
    {
        $firstLine = strtok($exception, "\n");

        return $firstLine === false ? 'Unknown exception.' : Str::limit($firstLine, 300);
    }
}
