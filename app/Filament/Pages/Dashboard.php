<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasModerationDashboardWidgets;
use App\Filament\Support\AdminNavigationGroup;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;
use UnitEnum;

class Dashboard extends BaseDashboard
{
    use HasModerationDashboardWidgets;

    protected string $view = 'filament.pages.moderation-dashboard';

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::OVERVIEW;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 10;

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
}
