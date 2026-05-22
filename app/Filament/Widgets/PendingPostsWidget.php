<?php

namespace App\Filament\Widgets;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PendingPostsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return [
            Stat::make(
                'Pending posts',
                Post::query()
                    ->where('status', PostStatus::Pending)
                    ->count(),
            )
                ->description('Posts waiting for review')
                ->url(PostResource::getUrl('index', [
                    'tableFilters' => [
                        'pending' => [
                            'isActive' => true,
                        ],
                    ],
                ])),
        ];
    }
}
