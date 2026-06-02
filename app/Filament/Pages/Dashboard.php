<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PendingPostsWidget;
use App\Filament\Widgets\ReportedCommentsWidget;
use App\Filament\Widgets\ReportedPostsWidget;
use App\Filament\Widgets\SuspiciousUsersWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;

class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.pages.moderation-dashboard';

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 1;

    public function getTitle(): string
    {
        return 'Dashboard';
    }

    public function getHeading(): string
    {
        return 'Dashboard';
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 4;
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
}
