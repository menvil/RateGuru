<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Comments\CommentResource;
use App\Queries\Comments\ModerationCommentsQuery;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReportedCommentsWidget extends StatsOverviewWidget
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
                'Reported comments',
                app(ModerationCommentsQuery::class)->count('reported'),
            )
                ->description('Comments requiring moderation attention')
                ->url(CommentResource::getUrl('index', ['status' => 'reported'])),
        ];
    }
}
