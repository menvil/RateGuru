<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Widgets\PendingPostsWidget;
use App\Filament\Widgets\ReportedCommentsWidget;
use App\Filament\Widgets\ReportedPostsWidget;
use App\Filament\Widgets\SuspiciousUsersWidget;
use Filament\Pages\Page;
use UnitEnum;

class ModerationDashboard extends Page
{
    protected string $view = 'filament.pages.moderation-dashboard';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::MODERATION;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?string $slug = 'moderation-dashboard';

    protected static bool $shouldRegisterNavigation = false;

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
