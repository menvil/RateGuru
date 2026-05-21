<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateTag extends CreateRecord
{
    protected static string $resource = TagResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Use a blank/null check (not truthy) so a deliberate slug like "0"
        // is preserved rather than falling back to the name.
        $data['slug'] = Str::slug(filled($data['slug']) ? $data['slug'] : $data['name']);

        return $data;
    }
}
