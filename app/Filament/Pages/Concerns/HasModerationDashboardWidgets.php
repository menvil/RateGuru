<?php

namespace App\Filament\Pages\Concerns;

use App\Filament\Widgets\PendingPostsWidget;
use App\Filament\Widgets\ReportedCommentsWidget;
use App\Filament\Widgets\ReportedPostsWidget;
use App\Filament\Widgets\SuspiciousUsersWidget;

trait HasModerationDashboardWidgets
{
    public function getHeaderWidgetsColumns(): int|array
    {
        return 4;
    }

    /**
     * @return array<class-string>
     */
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
