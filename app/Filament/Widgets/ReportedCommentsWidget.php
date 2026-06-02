<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Comments\CommentResource;
use App\Models\Comment;
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
                Comment::query()
                    ->where('reports_count', '>', 0)
                    ->count(),
            )
                ->description('Comments requiring moderation attention')
                ->url(CommentResource::getUrl('index', [
                    'tableFilters' => [
                        'reported' => [
                            'isActive' => true,
                        ],
                    ],
                ])),
        ];
    }
}
