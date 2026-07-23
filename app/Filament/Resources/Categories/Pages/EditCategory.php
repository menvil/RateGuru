<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Actions\Categories\UpdateCategoryAction;
use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Category $record */
        return app(UpdateCategoryAction::class)->handle(auth()->user(), $record, $data);
    }
}
