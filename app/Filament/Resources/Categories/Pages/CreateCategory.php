<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Actions\Categories\CreateCategoryAction;
use App\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateCategoryAction::class)->handle(auth()->user(), $data);
    }
}
