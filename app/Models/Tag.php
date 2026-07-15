<?php

namespace App\Models;

use App\Support\Translations\TranslatableField;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
        ];
    }

    public function translatedName(?string $locale = null): string
    {
        return TranslatableField::resolve($this->name_translations, $this->name, $locale);
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }
}
