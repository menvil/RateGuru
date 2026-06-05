<?php

namespace App\Filament\Resources\RatingGroups\Pages;

use App\Filament\Resources\RatingGroups\RatingGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRatingGroups extends ListRecords
{
    protected static string $resource = RatingGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
