<?php

namespace App\Filament\Widgets;

use App\Enums\UserStatus;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

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
                ->url(UserResource::getUrl('index')),
        ];
    }

    private function getSuspiciousUsersCount(): int
    {
        return User::query()
            ->where(function (Builder $query): void {
                $query
                    ->where('status', UserStatus::Shadowbanned)
                    ->orWhereHas('posts', fn (Builder $posts) => $posts->where('reports_count', '>', 0))
                    ->orWhereHas('comments', fn (Builder $comments) => $comments->where('reports_count', '>', 0));
            })
            ->count();
    }
}
