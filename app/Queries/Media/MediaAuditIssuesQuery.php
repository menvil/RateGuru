<?php

namespace App\Queries\Media;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\MediaAuditIssueSeverity;
use App\Enums\MediaAuditIssueType;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use App\Support\Database\LikePattern;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The issues one audit found, as Media diagnostics lists them: narrowed to a
 * severity and an issue type, and searched by asset id or path, the most
 * severe first. Without a completed audit there is nothing to list.
 */
final class MediaAuditIssuesQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    public const PER_PAGE = 25;

    /** @return LengthAwarePaginator<int, MediaAuditIssue> */
    public function paginate(?MediaAuditRun $run, ?MediaAuditIssueSeverity $severity, ?MediaAuditIssueType $type, string $search, int $page): LengthAwarePaginator
    {
        return $this->narrowed($run, $severity, $type, $search)
            ->with('variant')
            // Critical, then warning, then info: the enum's values are not
            // ordered, so the rank is spelled out, its values bound.
            ->orderByRaw('CASE media_audit_issues.severity WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [MediaAuditIssueSeverity::Critical->value, MediaAuditIssueSeverity::Warning->value])
            // Issues recorded in the same second keep their place across pages.
            ->orderByDesc('media_audit_issues.created_at')
            ->orderByDesc('media_audit_issues.id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * How many of the audit's issues there are of each severity and each
     * type, before any search.
     *
     * @return array{severity: array<string, int>, type: array<string, int>}
     */
    public function counts(?MediaAuditRun $run): array
    {
        $severity = [];
        $type = [];

        foreach (MediaAuditIssueSeverity::cases() as $case) {
            $severity[$case->value] = $this->narrowed($run, $case, null, '')->count();
        }

        foreach (MediaAuditIssueType::cases() as $case) {
            $type[$case->value] = $this->narrowed($run, null, $case, '')->count();
        }

        return ['severity' => $severity, 'type' => $type];
    }

    /** @return Builder<MediaAuditIssue> */
    private function narrowed(?MediaAuditRun $run, ?MediaAuditIssueSeverity $severity, ?MediaAuditIssueType $type, string $search): Builder
    {
        $query = MediaAuditIssue::query()
            // No completed audit yet: nothing to list. whereKey(0) says so
            // without raw SQL, since ids start at 1.
            ->when($run, fn (Builder $issues, MediaAuditRun $run) => $issues->where('media_audit_run_id', $run->id), fn (Builder $issues) => $issues->whereKey(0));
        $search = trim($search);

        if ($severity !== null) {
            $query->where('severity', $severity);
        }

        if ($type !== null) {
            $query->where('issue_type', $type);
        }

        if ($search !== '') {
            $query->where(function (Builder $matching) use ($search): void {
                // “#208113” or “208113” is an asset id, matched exactly.
                if (preg_match('/^#?(\d{1,18})$/', $search, $id) === 1) {
                    $matching->orWhere('media_asset_id', (int) $id[1]);
                }

                $matching->orWhereRaw("LOWER(path) LIKE LOWER(?) ESCAPE '!'", [LikePattern::containing($search)]);
            });
        }

        return $query;
    }
}
