<?php

namespace App\Filament\Widgets;

use App\Enums\MediaAuditRunStatus;
use App\Filament\Pages\MediaDiagnosticsPage;
use App\Models\MediaAuditRun;
use App\Services\Media\MediaHealthResolver;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * The Dashboard's own small summary — reads the latest persisted
 * MediaAuditRun (no filesystem scan, no per-asset query), and links out to
 * MediaDiagnosticsPage for everything else. Deliberately not the full
 * diagnostics console; that lives on its own page.
 */
class MediaHealthWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * Same gate MediaDiagnosticsPage itself uses — a moderator can see the
     * Dashboard (and its other widgets), but not this one, since it links to
     * and summarizes an admin-only page's data.
     */
    public static function canView(): bool
    {
        return Gate::allows('view-media-diagnostics');
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $latestCompleted = MediaAuditRun::query()
            ->where('status', MediaAuditRunStatus::Completed)
            // id as a tiebreaker: completed_at has only second-level
            // precision.
            ->latest('completed_at')
            ->latest('id')
            ->first();

        $health = app(MediaHealthResolver::class)->resolve($latestCompleted);
        $completedAt = $latestCompleted?->completed_at;

        $lastAuditDescription = $completedAt !== null
            ? 'Last audit: '.$completedAt->diffForHumans()
            : 'No audit has completed yet';

        return [
            Stat::make('Media health', ucfirst($health->value))
                ->description($lastAuditDescription)
                ->color($health->color())
                ->url(MediaDiagnosticsPage::getUrl()),
            Stat::make('Missing masters', (string) ($latestCompleted->missing_masters ?? '—'))
                ->color($latestCompleted !== null && $latestCompleted->missing_masters > 0 ? 'danger' : 'gray'),
            Stat::make('Missing variants', (string) ($latestCompleted->missing_variant_files ?? '—'))
                ->color($latestCompleted !== null && $latestCompleted->missing_variant_files > 0 ? 'warning' : 'gray'),
            Stat::make('Purgeable', (string) ($latestCompleted->purgeable_assets ?? '—')),
            Stat::make('Failed jobs', (string) ($latestCompleted->failed_media_jobs ?? '—'))
                ->color($latestCompleted !== null && $latestCompleted->failed_media_jobs > 0 ? 'warning' : 'gray'),
            Stat::make('Diagnostics', 'View')
                ->description('Full media diagnostics page')
                ->url(MediaDiagnosticsPage::getUrl()),
        ];
    }
}
