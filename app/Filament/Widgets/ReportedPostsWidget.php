<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Posts\PostResource;
use App\Queries\Posts\ModerationPostsQuery;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

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
                app(ModerationPostsQuery::class)->count('reported'),
            )
                ->description('Posts requiring moderation attention')
                ->url(PostResource::getUrl('index', ['status' => 'reported'])),
        ];
    }
}
