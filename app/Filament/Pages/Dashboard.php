<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\LatestReportsTable;
use App\Filament\Widgets\PendingPostsWidget;
use App\Filament\Widgets\ReportedCommentsWidget;
use App\Filament\Widgets\ReportedPostsWidget;
use App\Filament\Widgets\SuspiciousUsersWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.pages.moderation-dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 1;

    public function getTitle(): string
    {
        return 'Dashboard';
    }

    public function getHeading(): string
    {
        return 'Moderation Dashboard';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PendingPostsWidget::class,
            ReportedPostsWidget::class,
            ReportedCommentsWidget::class,
            SuspiciousUsersWidget::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            LatestReportsTable::class,
        ];
    }
}
