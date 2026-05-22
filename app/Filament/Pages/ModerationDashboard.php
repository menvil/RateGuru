<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Widgets\PendingPostsWidget;
use App\Filament\Widgets\ReportedCommentsWidget;
use App\Filament\Widgets\ReportedPostsWidget;
use Filament\Pages\Page;
use UnitEnum;

class ModerationDashboard extends Page
{
    protected string $view = 'filament.pages.moderation-dashboard';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::MODERATION;

    protected static ?string $navigationLabel = 'Moderation Dashboard';

    protected static ?string $title = 'Moderation Dashboard';

    protected static ?string $slug = 'moderation-dashboard';

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            PendingPostsWidget::class,
            ReportedPostsWidget::class,
            ReportedCommentsWidget::class,
        ];
    }
}
