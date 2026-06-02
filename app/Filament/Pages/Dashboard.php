<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasModerationDashboardWidgets;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;

class Dashboard extends BaseDashboard
{
    use HasModerationDashboardWidgets;

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
}
