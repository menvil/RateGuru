<?php

namespace App\Filament\Resources\RatingGroups;

use App\Filament\Resources\RatingGroups\Pages\CreateRatingGroup;
use App\Filament\Resources\RatingGroups\Pages\EditRatingGroup;
use App\Filament\Resources\RatingGroups\Pages\ListRatingGroups;
use App\Filament\Resources\RatingGroups\Schemas\RatingGroupForm;
use App\Filament\Resources\RatingGroups\Tables\RatingGroupsTable;
use App\Models\RatingGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RatingGroupResource extends Resource
{
    protected static ?string $model = RatingGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Rating groups';

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?int $navigationSort = 7;

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function form(Schema $schema): Schema
    {
        return RatingGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RatingGroupsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRatingGroups::route('/'),
            'create' => CreateRatingGroup::route('/create'),
            'edit' => EditRatingGroup::route('/{record}/edit'),
        ];
    }
}
