<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Tag;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Tags: one Admin v2 screen (ManageTags) that lists them and creates and
 * edits each in a drawer. The resource keeps the route, the navigation item,
 * the policy checks and global search, whose results open the tag in that
 * drawer.
 */
class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Tags';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return AdminNavigationGroup::CONTENT;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTags::route('/'),
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::canEdit($record) ? ManageTags::getUrl(['edit' => $record->getKey()]) : null;
    }
}
