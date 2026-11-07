<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Users\UserResource;
use App\Queries\Users\ModerationUsersQuery;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SuspiciousUsersWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return [
            Stat::make('Suspicious users', $this->getSuspiciousUsersCount())
                ->description('Users with reported content or shadowban status')
                ->url(UserResource::getUrl('index', ['suspicious' => 1])),
        ];
    }

    private function getSuspiciousUsersCount(): int
    {
        return app(ModerationUsersQuery::class)->suspicious();
    }
}
