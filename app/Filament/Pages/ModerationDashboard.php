<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasModerationDashboardWidgets;
use App\Filament\Support\AdminNavigationGroup;
use Filament\Pages\Page;
use UnitEnum;

class ModerationDashboard extends Page
{
    use HasModerationDashboardWidgets;

    protected string $view = 'filament.pages.moderation-dashboard';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::MODERATION;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?string $slug = 'moderation-dashboard';

    protected static bool $shouldRegisterNavigation = false;
}
