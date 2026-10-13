<?php

namespace App\Filament\Resources\RatingGroups;

use App\Filament\Resources\RatingGroups\Pages\CreateRatingGroup;
use App\Filament\Resources\RatingGroups\Pages\EditRatingGroup;
use App\Filament\Resources\RatingGroups\Pages\ManageRatingGroups;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\RatingGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;

/**
 * Rating groups: Admin v2 screens for the list (ManageRatingGroups), a new
 * group (CreateRatingGroup) and one group with its options
 * (EditRatingGroup). The resource keeps the routes, the navigation item, the
 * policy checks and global search, whose results open a group's editor.
 */
class RatingGroupResource extends Resource
{
    protected static ?string $model = RatingGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = null;

    public static function getNavigationLabel(): string
    {
        return __('admin.rating_groups.nav_label');
    }

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return AdminNavigationGroup::CONTENT;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRatingGroups::route('/'),
            'create' => CreateRatingGroup::route('/create'),
            'edit' => EditRatingGroup::route('/{record}/edit'),
        ];
    }
}
