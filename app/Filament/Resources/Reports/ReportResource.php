<?php

namespace App\Filament\Resources\Reports;

use App\Enums\ReportStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Report;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;

/**
 * Reports in the admin: one screen, ListReports, drawn in Admin v2. The
 * resource keeps the route and the navigation item, with its count of open
 * reports.
 */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'Reports';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return AdminNavigationGroup::MODERATION;
    }

    /** What waits for a moderator: the open reports, none when there are none. */
    public static function getNavigationBadge(): ?string
    {
        $open = Report::query()->where('status', ReportStatus::Open)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Open reports';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
        ];
    }
}
