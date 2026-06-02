<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class ReportedPostsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return [
            Stat::make(
                'Reported posts',
                Post::query()
                    ->where(fn (Builder $query) => $query
                        ->where('reports_count', '>', 0)
                        ->orWhere('needs_review', true))
                    ->count(),
            )
                ->description('Posts requiring moderation attention')
                ->url(PostResource::getUrl('index', [
                    'tableFilters' => [
                        'reported' => [
                            'isActive' => true,
                        ],
                    ],
                ])),
        ];
    }
}
