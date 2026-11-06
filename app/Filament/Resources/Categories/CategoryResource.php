<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Category;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Categories: one Admin v2 screen (ManageCategories) that lists them and
 * creates and edits each in a drawer. The resource keeps the route, the
 * navigation item, the policy checks and global search, whose results open
 * the category in that drawer.
 */
class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return AdminNavigationGroup::CONTENT;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::canEdit($record) ? ManageCategories::getUrl(['edit' => $record->getKey()]) : null;
    }
}
